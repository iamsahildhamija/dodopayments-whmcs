<?php
/**
 * Dodo Payments helper library for WHMCS.
 * Module version 1.0.1.
 *
 * Dependency-free by design for broad WHMCS 8.x / 9.x compatibility.
 */

if (!defined('DODOPAY_MODULE_VERSION')) {
    define('DODOPAY_MODULE_VERSION', '1.0.1');
}

if (!function_exists('dodopay_base_url')) {
    function dodopay_base_url($mode)
    {
        return strtolower((string) $mode) === 'live'
            ? 'https://live.dodopayments.com'
            : 'https://test.dodopayments.com';
    }
}

if (!function_exists('dodopay_credentials')) {
    function dodopay_credentials(array $params)
    {
        $mode = isset($params['mode']) && strtolower((string) $params['mode']) === 'live' ? 'live' : 'test';
        $prefix = $mode === 'live' ? 'live' : 'test';

        return array(
            'mode' => $mode,
            'apiKey' => isset($params[$prefix . 'ApiKey']) ? trim((string) $params[$prefix . 'ApiKey']) : '',
            'webhookSecret' => isset($params[$prefix . 'WebhookSecret']) ? trim((string) $params[$prefix . 'WebhookSecret']) : '',
            'productMap' => isset($params[$prefix . 'ProductMap']) ? (string) $params[$prefix . 'ProductMap'] : '',
            'instanceId' => isset($params['instanceId']) ? trim((string) $params['instanceId']) : '',
        );
    }
}

if (!function_exists('dodopay_validate_instance_id')) {
    function dodopay_validate_instance_id($instanceId)
    {
        $instanceId = trim((string) $instanceId);
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $instanceId)) {
            throw new RuntimeException('WHMCS Instance ID must be 8-64 characters using only letters, numbers, underscore, or hyphen. Use a different value on every WHMCS installation.');
        }
        return $instanceId;
    }
}

if (!function_exists('dodopay_parse_product_map')) {
    function dodopay_parse_product_map($raw)
    {
        $map = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);

        if (!is_array($lines)) {
            return $map;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || substr($line, 0, 1) === '#' || substr($line, 0, 1) === ';') {
                continue;
            }

            $parts = preg_split('/\s*[=:]\s*/', $line, 2);
            if (!is_array($parts) || count($parts) !== 2) {
                continue;
            }

            $currency = strtoupper(trim($parts[0]));
            $productId = trim($parts[1]);

            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $productId)) {
                continue;
            }

            $map[$currency] = $productId;
        }

        return $map;
    }
}

if (!function_exists('dodopay_currency_exponent')) {
    function dodopay_currency_exponent($currency)
    {
        $currency = strtoupper((string) $currency);

        // Common payment-API exponents for ISO 4217 currencies.
        $zeroDecimal = array(
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG',
            'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
        );
        $threeDecimal = array('BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND');

        if (in_array($currency, $zeroDecimal, true)) {
            return 0;
        }
        if (in_array($currency, $threeDecimal, true)) {
            return 3;
        }
        return 2;
    }
}

if (!function_exists('dodopay_amount_to_minor')) {
    function dodopay_amount_to_minor($amount, $currency)
    {
        $amount = trim((string) $amount);
        $currency = strtoupper(trim((string) $currency));

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('Invalid ISO currency code.');
        }
        if (!preg_match('/^\d+(?:\.\d+)?$/', $amount)) {
            throw new RuntimeException('Invalid monetary amount.');
        }

        $exponent = dodopay_currency_exponent($currency);
        $parts = explode('.', $amount, 2);
        $whole = ltrim($parts[0], '0');
        if ($whole === '') {
            $whole = '0';
        }
        $fraction = isset($parts[1]) ? $parts[1] : '';

        // Round half-up to the currency exponent without using floating point.
        if ($exponent === 0) {
            $minor = $whole;
            if ($fraction !== '' && (int) substr($fraction . '0', 0, 1) >= 5) {
                $minor = dodopay_decimal_string_add_one($minor);
            }
        } else {
            $kept = substr($fraction . str_repeat('0', $exponent), 0, $exponent);
            $roundDigit = strlen($fraction) > $exponent ? (int) $fraction[$exponent] : 0;
            $minor = ltrim($whole . $kept, '0');
            if ($minor === '') {
                $minor = '0';
            }
            if ($roundDigit >= 5) {
                $minor = dodopay_decimal_string_add_one($minor);
            }
        }

        // Dodo amount/refund schemas are integer-based. Keep within signed int32
        // to fail locally instead of sending an out-of-range request.
        if (strlen($minor) > 10 || (strlen($minor) === 10 && strcmp($minor, '2147483647') > 0)) {
            throw new RuntimeException('Amount exceeds the supported Dodo API integer range.');
        }

        return (int) $minor;
    }
}

if (!function_exists('dodopay_decimal_string_add_one')) {
    function dodopay_decimal_string_add_one($number)
    {
        $number = (string) $number;
        $carry = 1;
        $out = '';
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $digit = (int) $number[$i] + $carry;
            if ($digit >= 10) {
                $digit -= 10;
                $carry = 1;
            } else {
                $carry = 0;
            }
            $out = (string) $digit . $out;
        }
        if ($carry) {
            $out = '1' . $out;
        }
        return $out;
    }
}

if (!function_exists('dodopay_minor_to_decimal')) {
    function dodopay_minor_to_decimal($minor, $currency)
    {
        $minor = (int) $minor;
        if ($minor < 0) {
            throw new RuntimeException('Minor amount cannot be negative.');
        }

        $exponent = dodopay_currency_exponent($currency);
        if ($exponent === 0) {
            return (string) $minor;
        }

        $digits = str_pad((string) $minor, $exponent + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$exponent);
        $fraction = substr($digits, -$exponent);
        return $whole . '.' . $fraction;
    }
}

if (!function_exists('dodopay_bool')) {
    function dodopay_bool($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        $value = strtolower(trim((string) $value));
        return in_array($value, array('1', 'true', 'yes', 'on'), true);
    }
}

if (!function_exists('dodopay_clean_text')) {
    function dodopay_clean_text($value, $maxLength)
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $value);
        if (!is_string($value)) {
            $value = '';
        }
        $value = trim($value);
        if ($maxLength > 0) {
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($value, 'UTF-8') > $maxLength) {
                    $value = mb_substr($value, 0, $maxLength, 'UTF-8');
                }
            } elseif (strlen($value) > $maxLength) {
                $value = substr($value, 0, $maxLength);
            }
        }
        return $value;
    }
}

if (!function_exists('dodopay_api_error_message')) {
    function dodopay_api_error_message(array $decoded, $status)
    {
        foreach (array('message', 'error') as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                return trim($decoded[$key]);
            }
        }
        if (isset($decoded['detail'])) {
            if (is_string($decoded['detail']) && trim($decoded['detail']) !== '') {
                return trim($decoded['detail']);
            }
            if (is_array($decoded['detail'])) {
                $json = json_encode($decoded['detail']);
                if (is_string($json) && $json !== '') {
                    return strlen($json) > 500 ? substr($json, 0, 500) . '...' : $json;
                }
            }
        }
        return 'Dodo Payments API returned HTTP ' . (int) $status . '.';
    }
}

if (!function_exists('dodopay_api_request')) {
    function dodopay_api_request($apiKey, $mode, $method, $path, $body)
    {
        $apiKey = trim((string) $apiKey);
        if ($apiKey === '') {
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'API key is not configured.');
        }
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'PHP cURL extension is required.');
        }

        $method = strtoupper((string) $method);
        $url = rtrim(dodopay_base_url($mode), '/') . '/' . ltrim((string) $path, '/');
        $ch = curl_init($url);
        if ($ch === false) {
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'Could not initialize cURL.');
        }

        $headers = array(
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: WHMCS-DodoPayments/' . DODOPAY_MODULE_VERSION,
        );

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                curl_close($ch);
                return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'Could not encode request as JSON.');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return array('ok' => false, 'status' => $status, 'data' => null, 'error' => $curlError !== '' ? $curlError : 'Dodo Payments API request failed.');
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $decoded = array();
        }

        $ok = $status >= 200 && $status < 300;
        return array(
            'ok' => $ok,
            'status' => $status,
            'data' => $decoded,
            'error' => $ok ? '' : dodopay_api_error_message($decoded, $status),
        );
    }
}

if (!function_exists('dodopay_safe_api_log')) {
    function dodopay_safe_api_log($action, array $requestSummary, array $response, $apiKey)
    {
        if (!function_exists('logModuleCall')) {
            return;
        }

        $processed = array(
            'ok' => !empty($response['ok']),
            'http_status' => isset($response['status']) ? (int) $response['status'] : 0,
            'error' => isset($response['error']) ? (string) $response['error'] : '',
        );
        if (isset($response['data']['session_id'])) {
            $processed['session_id'] = (string) $response['data']['session_id'];
        }
        if (isset($response['data']['payment_id'])) {
            $processed['payment_id'] = (string) $response['data']['payment_id'];
        }
        if (isset($response['data']['refund_id'])) {
            $processed['refund_id'] = (string) $response['data']['refund_id'];
        }
        if (isset($response['data']['status'])) {
            $processed['status'] = (string) $response['data']['status'];
        }

        logModuleCall('dodopay', $action, $requestSummary, $processed, $processed, array((string) $apiKey));
    }
}

if (!function_exists('dodopay_validate_product')) {
    function dodopay_validate_product($apiKey, $mode, $productId, $currency, $amountMinor)
    {
        $response = dodopay_api_request($apiKey, $mode, 'GET', '/products/' . rawurlencode($productId), null);
        dodopay_safe_api_log('validate_product', array('product_id' => $productId, 'currency' => strtoupper($currency)), $response, $apiKey);

        if (empty($response['ok'])) {
            throw new RuntimeException('Unable to validate the configured Dodo product: ' . $response['error']);
        }

        $product = is_array($response['data']) ? $response['data'] : array();
        $price = array();
        if (isset($product['price']) && is_array($product['price'])) {
            $price = $product['price'];
        } elseif (isset($product['price_detail']) && is_array($product['price_detail'])) {
            $price = $product['price_detail'];
        }

        if (!empty($product['is_recurring'])) {
            throw new RuntimeException('The configured Dodo product must be a one-time product, not a subscription.');
        }
        if (isset($price['type']) && (string) $price['type'] !== '' && (string) $price['type'] !== 'one_time_price') {
            throw new RuntimeException('The configured Dodo product must use Single Payment / one-time pricing.');
        }
        if (!isset($price['pay_what_you_want']) || !dodopay_bool($price['pay_what_you_want'])) {
            throw new RuntimeException('Enable Pay What You Want on the configured Dodo product.');
        }

        $taxInclusive = isset($price['tax_inclusive'])
            ? dodopay_bool($price['tax_inclusive'])
            : (isset($product['tax_inclusive']) ? dodopay_bool($product['tax_inclusive']) : false);
        if (!$taxInclusive) {
            throw new RuntimeException('Enable Tax Inclusive pricing on the configured Dodo product so the Dodo checkout total matches the WHMCS invoice total.');
        }

        if (isset($price['purchasing_power_parity']) && dodopay_bool($price['purchasing_power_parity'])) {
            throw new RuntimeException('Disable Purchasing Power Parity/adaptive pricing on the configured Dodo product so the invoice amount cannot change.');
        }

        if (isset($price['price']) && is_numeric($price['price']) && (int) $price['price'] > (int) $amountMinor) {
            throw new RuntimeException('The WHMCS invoice amount is below this Dodo Pay What You Want product minimum price. Lower the Dodo product minimum or use a different mapped product.');
        }

        $productCurrency = '';
        if (isset($price['currency'])) {
            $productCurrency = strtoupper(trim((string) $price['currency']));
        } elseif (isset($product['currency'])) {
            $productCurrency = strtoupper(trim((string) $product['currency']));
        }
        if ($productCurrency === '' || $productCurrency !== strtoupper((string) $currency)) {
            throw new RuntimeException('The configured Dodo product currency (' . ($productCurrency !== '' ? $productCurrency : 'unknown') . ') does not match the WHMCS invoice currency (' . strtoupper((string) $currency) . ').');
        }

        return true;
    }
}

if (!function_exists('dodopay_header_value')) {
    function dodopay_header_value($name)
    {
        $normalized = strtoupper(str_replace('-', '_', (string) $name));
        $serverKey = 'HTTP_' . $normalized;
        if (isset($_SERVER[$serverKey])) {
            return trim((string) $_SERVER[$serverKey]);
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strcasecmp((string) $key, (string) $name) === 0) {
                        return trim((string) $value);
                    }
                }
            }
        }
        return '';
    }
}

if (!function_exists('dodopay_decode_webhook_secret')) {
    function dodopay_decode_webhook_secret($secret)
    {
        $secret = trim((string) $secret);
        if (strpos($secret, 'whsec_') === 0) {
            $secret = substr($secret, 6);
        }

        $decoded = base64_decode($secret, true);
        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }

        // Compatibility fallback for literal/raw secrets.
        return $secret;
    }
}

if (!function_exists('dodopay_verify_webhook')) {
    function dodopay_verify_webhook($payload, $webhookId, $webhookTimestamp, $signatureHeader, $secret, $now)
    {
        $payload = (string) $payload;
        $webhookId = trim((string) $webhookId);
        $webhookTimestamp = trim((string) $webhookTimestamp);
        $signatureHeader = trim((string) $signatureHeader);
        $secret = trim((string) $secret);

        if ($webhookId === '' || $webhookTimestamp === '' || $signatureHeader === '' || $secret === '') {
            return false;
        }
        if (!preg_match('/^\d+$/', $webhookTimestamp)) {
            return false;
        }

        $timestamp = (int) $webhookTimestamp;
        $now = $now === null ? time() : (int) $now;
        if (abs($now - $timestamp) > 300) {
            return false;
        }

        $signingKey = dodopay_decode_webhook_secret($secret);
        if ($signingKey === '') {
            return false;
        }

        $signedContent = $webhookId . '.' . $webhookTimestamp . '.' . $payload;
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $signingKey, true));
        $signatures = preg_split('/\s+/', $signatureHeader);
        if (!is_array($signatures)) {
            return false;
        }

        foreach ($signatures as $signature) {
            $parts = explode(',', trim($signature), 2);
            if (count($parts) !== 2 || trim($parts[0]) !== 'v1') {
                continue;
            }
            if (hash_equals($expected, trim($parts[1]))) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('dodopay_sanitize_webhook_for_log')) {
    function dodopay_sanitize_webhook_for_log(array $event, $webhookId)
    {
        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array();

        return array(
            'webhook_id' => (string) $webhookId,
            'event_type' => isset($event['type']) ? (string) $event['type'] : '',
            'payload_type' => isset($data['payload_type']) ? (string) $data['payload_type'] : '',
            'business_id' => isset($event['business_id']) ? (string) $event['business_id'] : (isset($data['business_id']) ? (string) $data['business_id'] : ''),
            'payment_id' => isset($data['payment_id']) ? (string) $data['payment_id'] : '',
            'status' => isset($data['status']) ? (string) $data['status'] : '',
            'currency' => isset($data['currency']) ? (string) $data['currency'] : '',
            'total_amount' => isset($data['total_amount']) ? $data['total_amount'] : null,
            'whmcs_invoice_id' => isset($metadata['whmcs_invoice_id']) ? (string) $metadata['whmcs_invoice_id'] : '',
            'whmcs_instance_id' => isset($metadata['whmcs_instance_id']) ? (string) $metadata['whmcs_instance_id'] : '',
        );
    }
}
