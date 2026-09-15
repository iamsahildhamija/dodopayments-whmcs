<?php
/**
 * Dodo Payments webhook callback for WHMCS.
 * Version 1.0.1
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once dirname(__DIR__) . '/dodopay/lib.php';

$gatewayModuleName = 'dodopay';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    http_response_code(503);
    echo 'Gateway not active';
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method not allowed';
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    echo 'Empty request';
    exit;
}

$credentials = dodopay_credentials($gatewayParams);
try {
    $instanceId = dodopay_validate_instance_id($credentials['instanceId']);
} catch (Exception $e) {
    if (function_exists('logTransaction')) {
        logTransaction('Dodo Payments', array('error' => $e->getMessage()), 'Webhook Configuration Error');
    }
    http_response_code(503);
    echo 'Webhook not configured';
    exit;
}

if ($credentials['webhookSecret'] === '') {
    if (function_exists('logTransaction')) {
        logTransaction('Dodo Payments', array('error' => ucfirst($credentials['mode']) . ' webhook secret is not configured.'), 'Webhook Configuration Error');
    }
    http_response_code(503);
    echo 'Webhook not configured';
    exit;
}

$webhookId = dodopay_header_value('webhook-id');
$webhookSignature = dodopay_header_value('webhook-signature');
$webhookTimestamp = dodopay_header_value('webhook-timestamp');

if (!dodopay_verify_webhook($rawBody, $webhookId, $webhookTimestamp, $webhookSignature, $credentials['webhookSecret'], null)) {
    if (function_exists('logTransaction')) {
        logTransaction('Dodo Payments', array('webhook_id' => $webhookId), 'Invalid Webhook Signature');
    }
    http_response_code(401);
    echo 'Invalid signature';
    exit;
}

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

$eventType = isset($event['type']) ? (string) $event['type'] : '';
if ($eventType !== 'payment.succeeded') {
    if (function_exists('logTransaction')) {
        logTransaction('Dodo Payments', dodopay_sanitize_webhook_for_log($event, $webhookId), 'Webhook Ignored');
    }
    http_response_code(200);
    echo 'OK';
    exit;
}

$data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();
$payloadType = isset($data['payload_type']) ? trim((string) $data['payload_type']) : '';
if ($payloadType !== '' && strcasecmp($payloadType, 'Payment') !== 0) {
    if (function_exists('logTransaction')) {
        logTransaction('Dodo Payments', dodopay_sanitize_webhook_for_log($event, $webhookId), 'Webhook Validation Error - Unexpected Payload Type');
    }
    http_response_code(422);
    echo 'Unexpected payload type';
    exit;
}

$metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array();
$metadataGateway = isset($metadata['whmcs_gateway']) ? (string) $metadata['whmcs_gateway'] : '';
$metadataInstance = isset($metadata['whmcs_instance_id']) ? (string) $metadata['whmcs_instance_id'] : '';
$logData = dodopay_sanitize_webhook_for_log($event, $webhookId);

// A Dodo business can have multiple integrations/webhook endpoints. Signed
// payments not created by this gateway/installation are valid Dodo events, so
// acknowledge them with 200 rather than triggering retries or touching WHMCS.
if ($metadataGateway !== 'dodopay' || $metadataInstance !== $instanceId) {
    if (function_exists('logTransaction')) {
        logTransaction('Dodo Payments', $logData, 'Webhook Ignored - Different Integration');
    }
    http_response_code(200);
    echo 'OK';
    exit;
}

$invoiceId = isset($metadata['whmcs_invoice_id']) ? (int) $metadata['whmcs_invoice_id'] : 0;
$expectedCurrency = isset($metadata['whmcs_currency']) ? strtoupper((string) $metadata['whmcs_currency']) : '';
$expectedMinorString = isset($metadata['whmcs_amount_minor']) ? (string) $metadata['whmcs_amount_minor'] : '';
$paymentId = isset($data['payment_id']) ? trim((string) $data['payment_id']) : '';
$actualCurrency = isset($data['currency']) ? strtoupper((string) $data['currency']) : '';
$actualMinor = isset($data['total_amount']) && is_numeric($data['total_amount']) ? (int) $data['total_amount'] : -1;
$paymentStatus = isset($data['status']) ? strtolower((string) $data['status']) : 'succeeded';

if ($invoiceId <= 0 || !preg_match('/^[A-Z]{3}$/', $expectedCurrency) || !preg_match('/^\d+$/', $expectedMinorString) || $paymentId === '' || $actualMinor <= 0) {
    if (function_exists('logTransaction')) {
        logTransaction('Dodo Payments', $logData, 'Webhook Validation Error');
    }
    http_response_code(422);
    echo 'Invalid payment metadata';
    exit;
}

if ($paymentStatus !== 'succeeded') {
    if (function_exists('logTransaction')) {
        logTransaction('Dodo Payments', $logData, 'Payment Not Succeeded');
    }
    http_response_code(422);
    echo 'Payment not succeeded';
    exit;
}

$expectedMinor = (int) $expectedMinorString;
if ($actualCurrency !== $expectedCurrency || $actualMinor !== $expectedMinor) {
    if (function_exists('logTransaction')) {
        $logData['expected_currency'] = $expectedCurrency;
        $logData['expected_total_amount'] = $expectedMinor;
        logTransaction('Dodo Payments', $logData, 'Amount/Currency Mismatch');
    }
    http_response_code(422);
    echo 'Amount or currency mismatch';
    exit;
}

// Standard WHMCS callback validation and transaction-level idempotency.
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
checkCbTransID($paymentId);

$paymentAmount = dodopay_minor_to_decimal($expectedMinor, $expectedCurrency);
if ((float) $paymentAmount <= 0) {
    if (function_exists('logTransaction')) {
        logTransaction('Dodo Payments', $logData, 'Invalid Payment Amount');
    }
    http_response_code(422);
    echo 'Invalid amount';
    exit;
}

logTransaction('Dodo Payments', $logData, 'Successful');
addInvoicePayment(
    $invoiceId,
    $paymentId,
    $paymentAmount,
    0.00,
    $gatewayModuleName
);

http_response_code(200);
echo 'OK';
