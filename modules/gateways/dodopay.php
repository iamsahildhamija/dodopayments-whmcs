<?php
/**
 * Dodo Payments third-party gateway module for WHMCS 8.x / 9.x.
 * Version 1.0.1
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/dodopay/lib.php';

function dodopay_MetaData()
{
    return array(
        'DisplayName' => 'Dodo Payments Gateway',
        'APIVersion' => '1.1',
    );
}

function dodopay_config()
{
    $productMapDescription = 'One Dodo product per WHMCS invoice currency, one mapping per line (example: USD=pdt_xxx). The mapped product must be Single Payment, Pay What You Want enabled, Tax Inclusive enabled, and Purchasing Power Parity/adaptive pricing disabled.';

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Dodo Payments',
        ),
        'instanceId' => array(
            'FriendlyName' => 'WHMCS Instance ID',
            'Type' => 'text',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Required. Unique per WHMCS installation (8-64 letters/numbers/_/-). Example: whmcs_a8f14e45. Prevents cross-site webhook credits if one Dodo account is shared by multiple WHMCS sites.',
        ),
        'mode' => array(
            'FriendlyName' => 'Environment',
            'Type' => 'dropdown',
            'Options' => array(
                'test' => 'Test',
                'live' => 'Live',
            ),
            'Default' => 'test',
            'Description' => 'Test and Live Dodo modes use separate API keys, products, and webhook secrets.',
        ),
        'testApiKey' => array(
            'FriendlyName' => 'Test API Key',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
        ),
        'testWebhookSecret' => array(
            'FriendlyName' => 'Test Webhook Secret',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
        ),
        'testProductMap' => array(
            'FriendlyName' => 'Test Product Map',
            'Type' => 'textarea',
            'Rows' => '8',
            'Cols' => '60',
            'Default' => '',
            'Description' => $productMapDescription,
        ),
        'liveApiKey' => array(
            'FriendlyName' => 'Live API Key',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
        ),
        'liveWebhookSecret' => array(
            'FriendlyName' => 'Live Webhook Secret',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
        ),
        'liveProductMap' => array(
            'FriendlyName' => 'Live Product Map',
            'Type' => 'textarea',
            'Rows' => '8',
            'Cols' => '60',
            'Default' => '',
            'Description' => $productMapDescription,
        ),
        'buttonText' => array(
            'FriendlyName' => 'Payment Button Text',
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'Pay with Dodo Payments',
        ),
    );
}

function dodopay_link($params)
{
    try {
        $credentials = dodopay_credentials($params);
        $instanceId = dodopay_validate_instance_id($credentials['instanceId']);
        if ($credentials['apiKey'] === '') {
            throw new RuntimeException(ucfirst($credentials['mode']) . ' Dodo API key is not configured.');
        }

        $invoiceId = isset($params['invoiceid']) ? (int) $params['invoiceid'] : 0;
        $currency = isset($params['currency']) ? strtoupper(trim((string) $params['currency'])) : '';
        $amount = isset($params['amount']) ? (string) $params['amount'] : '';

        if ($invoiceId <= 0 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('WHMCS supplied invalid invoice information.');
        }

        // No site-specific or fixed currency allow-list: the mapping and Dodo API
        // are the source of truth, making this reusable for any supported currency.
        $amountMinor = dodopay_amount_to_minor($amount, $currency);
        if ($amountMinor <= 0) {
            return '<div class="alert alert-info">No payment is due for this invoice.</div>';
        }

        $productMap = dodopay_parse_product_map($credentials['productMap']);
        if (!isset($productMap[$currency])) {
            throw new RuntimeException('No Dodo product is mapped for invoice currency ' . $currency . '.');
        }
        $productId = $productMap[$currency];

        // Fail closed if a mapped product could change the WHMCS invoice total.
        dodopay_validate_product($credentials['apiKey'], $credentials['mode'], $productId, $currency, $amountMinor);

        $clientDetails = isset($params['clientdetails']) && is_array($params['clientdetails']) ? $params['clientdetails'] : array();
        $name = trim(
            (isset($clientDetails['firstname']) ? (string) $clientDetails['firstname'] : '') . ' ' .
            (isset($clientDetails['lastname']) ? (string) $clientDetails['lastname'] : '')
        );
        $email = isset($clientDetails['email']) ? trim((string) $clientDetails['email']) : '';

        // Dodo's "new customer" object requires a valid email. If WHMCS has no
        // valid email, omit the object and let hosted checkout collect it.
        $customer = array();
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $customer['email'] = $email;
            if ($name !== '') {
                $customer['name'] = dodopay_clean_text($name, 200);
            }
        }

        $billingAddress = array();
        $country = isset($clientDetails['country']) ? strtoupper(trim((string) $clientDetails['country'])) : '';
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $billingAddress['country'] = $country;
        }
        if (!empty($clientDetails['city'])) {
            $billingAddress['city'] = dodopay_clean_text($clientDetails['city'], 200);
        }
        if (!empty($clientDetails['state'])) {
            $billingAddress['state'] = dodopay_clean_text($clientDetails['state'], 200);
        }
        if (!empty($clientDetails['address1'])) {
            $street = (string) $clientDetails['address1'];
            if (!empty($clientDetails['address2'])) {
                $street .= ', ' . (string) $clientDetails['address2'];
            }
            $billingAddress['street'] = dodopay_clean_text($street, 300);
        }
        if (!empty($clientDetails['postcode'])) {
            $billingAddress['zipcode'] = dodopay_clean_text($clientDetails['postcode'], 50);
        }

        $returnUrl = isset($params['returnurl']) ? trim((string) $params['returnurl']) : '';
        $returnScheme = strtolower((string) parse_url($returnUrl, PHP_URL_SCHEME));
        if ($returnUrl === '' || !filter_var($returnUrl, FILTER_VALIDATE_URL) || !in_array($returnScheme, array('http', 'https'), true)) {
            throw new RuntimeException('WHMCS return URL is invalid.');
        }

        $request = array(
            'product_cart' => array(
                array(
                    'product_id' => $productId,
                    'quantity' => 1,
                    'amount' => $amountMinor,
                ),
            ),
            'return_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'metadata' => array(
                'whmcs_gateway' => 'dodopay',
                'whmcs_instance_id' => $instanceId,
                'whmcs_invoice_id' => (string) $invoiceId,
                'whmcs_currency' => $currency,
                'whmcs_amount_minor' => (string) $amountMinor,
            ),
            'feature_flags' => array(
                'allow_currency_selection' => false,
                'allow_discount_code' => false,
                'allow_editing_addons' => false,
                'always_create_new_customer' => false,
            ),
            'show_saved_payment_methods' => false,
        );

        if (!empty($customer)) {
            $request['customer'] = $customer;
        }
        if (!empty($billingAddress)) {
            $request['billing_address'] = $billingAddress;
        }

        $response = dodopay_api_request($credentials['apiKey'], $credentials['mode'], 'POST', '/checkouts', $request);
        dodopay_safe_api_log(
            'create_checkout',
            array(
                'invoice_id' => $invoiceId,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'product_id' => $productId,
                'mode' => $credentials['mode'],
                'instance_id_hash' => substr(hash('sha256', $instanceId), 0, 12),
            ),
            $response,
            $credentials['apiKey']
        );

        if (empty($response['ok'])) {
            throw new RuntimeException('Dodo checkout could not be created: ' . $response['error']);
        }

        $checkoutUrl = isset($response['data']['checkout_url']) ? trim((string) $response['data']['checkout_url']) : '';
        if ($checkoutUrl === '' || !filter_var($checkoutUrl, FILTER_VALIDATE_URL) || strtolower((string) parse_url($checkoutUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('Dodo Payments did not return a valid secure checkout URL.');
        }

        $buttonText = isset($params['buttonText']) && trim((string) $params['buttonText']) !== ''
            ? trim((string) $params['buttonText'])
            : 'Pay with Dodo Payments';

        return '<form method="get" action="' . htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" class="btn btn-primary">' . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') . '</button>'
            . '</form>';
    } catch (Exception $e) {
        if (function_exists('logTransaction')) {
            logTransaction(
                'Dodo Payments',
                array('invoice_id' => isset($params['invoiceid']) ? (int) $params['invoiceid'] : 0, 'error' => $e->getMessage()),
                'Checkout Error'
            );
        }

        return '<div class="alert alert-danger">Dodo Payments is temporarily unavailable for this invoice. Please contact support.</div>';
    }
}

function dodopay_refund($params)
{
    try {
        $credentials = dodopay_credentials($params);
        $instanceId = dodopay_validate_instance_id($credentials['instanceId']);
        if ($credentials['apiKey'] === '') {
            throw new RuntimeException(ucfirst($credentials['mode']) . ' Dodo API key is not configured.');
        }

        $paymentId = isset($params['transid']) ? trim((string) $params['transid']) : '';
        $currency = isset($params['currency']) ? strtoupper(trim((string) $params['currency'])) : '';
        $refundAmount = isset($params['amount']) ? (string) $params['amount'] : '';
        $invoiceId = isset($params['invoiceid']) ? (int) $params['invoiceid'] : 0;

        if ($paymentId === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('Missing original Dodo payment ID or currency.');
        }

        $refundMinor = dodopay_amount_to_minor($refundAmount, $currency);
        if ($refundMinor <= 0) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }

        $lineResponse = dodopay_api_request($credentials['apiKey'], $credentials['mode'], 'GET', '/payments/' . rawurlencode($paymentId) . '/line-items', null);
        dodopay_safe_api_log('retrieve_line_items', array('payment_id' => $paymentId), $lineResponse, $credentials['apiKey']);
        if (empty($lineResponse['ok'])) {
            throw new RuntimeException('Unable to retrieve refundable Dodo line items: ' . $lineResponse['error']);
        }

        $lineCurrency = isset($lineResponse['data']['currency']) ? strtoupper((string) $lineResponse['data']['currency']) : '';
        if ($lineCurrency !== '' && $lineCurrency !== $currency) {
            throw new RuntimeException('Refund currency does not match the original Dodo payment.');
        }

        $items = isset($lineResponse['data']['items']) && is_array($lineResponse['data']['items']) ? $lineResponse['data']['items'] : array();
        if (count($items) !== 1) {
            throw new RuntimeException('This gateway creates exactly one Dodo line item per WHMCS invoice payment; the original Dodo payment has an unexpected line-item structure.');
        }

        $item = $items[0];
        $refundableMinor = isset($item['refundable_amount']) ? (int) $item['refundable_amount'] : 0;
        if ($refundableMinor <= 0) {
            throw new RuntimeException('The Dodo payment has no refundable balance.');
        }
        if ($refundMinor > $refundableMinor) {
            throw new RuntimeException('Requested refund exceeds the remaining refundable Dodo amount.');
        }

        $request = array(
            'payment_id' => $paymentId,
            'reason' => $invoiceId > 0 ? 'WHMCS invoice #' . $invoiceId . ' refund' : 'WHMCS refund',
            'metadata' => array(
                'whmcs_gateway' => 'dodopay',
                'whmcs_instance_id' => $instanceId,
                'whmcs_invoice_id' => (string) $invoiceId,
            ),
        );

        // Dodo uses the original line item ID for a partial refund. Omitting
        // items requests a full refund of the remaining refundable balance.
        if ($refundMinor < $refundableMinor) {
            $itemId = isset($item['items_id']) ? trim((string) $item['items_id']) : '';
            if ($itemId === '') {
                throw new RuntimeException('Dodo did not return the line-item ID needed for a partial refund.');
            }
            $request['items'] = array(
                array(
                    'item_id' => $itemId,
                    'amount' => $refundMinor,
                    'tax_inclusive' => true,
                ),
            );
        }

        $response = dodopay_api_request($credentials['apiKey'], $credentials['mode'], 'POST', '/refunds', $request);
        dodopay_safe_api_log(
            'create_refund',
            array('payment_id' => $paymentId, 'invoice_id' => $invoiceId, 'amount_minor' => $refundMinor),
            $response,
            $credentials['apiKey']
        );

        if (empty($response['ok'])) {
            throw new RuntimeException('Dodo refund failed: ' . $response['error']);
        }

        $status = isset($response['data']['status']) ? strtolower((string) $response['data']['status']) : '';
        $refundId = isset($response['data']['refund_id']) ? trim((string) $response['data']['refund_id']) : '';

        if ($status !== 'succeeded') {
            return array(
                'status' => 'error',
                'rawdata' => array(
                    'message' => 'Dodo accepted the refund request, but its status is ' . ($status !== '' ? $status : 'unknown') . '. Do not submit a second refund until you verify this refund in Dodo.',
                    'refund_status' => $status,
                    'refund_id' => $refundId,
                ),
                'transid' => $refundId,
            );
        }

        return array(
            'status' => 'success',
            'rawdata' => array('refund_status' => $status, 'refund_id' => $refundId),
            'transid' => $refundId,
            'fees' => 0,
        );
    } catch (Exception $e) {
        return array(
            'status' => 'error',
            'rawdata' => array('message' => $e->getMessage()),
        );
    }
}
