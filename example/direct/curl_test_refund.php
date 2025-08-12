<?php
/**
 * Refund Initiation via cURL, supports V1 and V2 surgically
 *
 * What this does
 * - Builds and sends a refund request to pawaPay, either V1 (/refunds) or V2 (/v2/refunds)
 * - Switches by one variable, $apiVersion, default v1 to preserve old behavior
 * - Prints exact raw JSON when $OUTPUT_RAW_JSON is true, friendly text otherwise
 *
 * Quick start
 * - Set $environment to sandbox or production
 * - Paste your API tokens into $config
 * - Set $apiVersion to v1 or v2
 * - Fill $depositIdToRefund and $amount, for V2 also set $currency
 * - Run the script from CLI or web, you will see the API response
 *
 * Notes
 * - V1 payload keys: refundId, depositId, amount, metadata[] with fieldName, fieldValue, isPII?
 * - V2 payload keys: refundId, depositId, amount, currency, metadata[] with free form key values
 * - Signature headers are only needed if you enabled signed requests in dashboard
 * - SSL peer verification is disabled for sandbox in this example, enable it in production
 */

/**
 * Generate a UUID v4 for refundId
 *
 * @return string
 */
function generateUuidV4(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

/**
 * Output mode
 * true  prints exact raw JSON from API, no extra text
 * false prints readable info with status and parsed fields
 */
$OUTPUT_RAW_JSON = true;

/**
 * Environment configuration
 * Paste your real tokens
 */
$config = [
    'sandbox' => [
        'api_url'   => 'https://api.sandbox.pawapay.io',
        'api_token' => 'your_sandbox_api_token_here'
    ],
    'production' => [
        'api_url'   => 'https://api.pawapay.io',
        'api_token' => 'your_production_api_token_here'
    ],
];

/**
 * Choose environment and API version
 * - $apiVersion = 'v1' or 'v2'
 * - Default is v1 to keep legacy behavior stable
 */
$environment = 'sandbox';
$apiVersion  = 'v1'; // change to 'v2' to use the V2 refunds endpoint

// Validate environment
if (!isset($config[$environment])) {
    $msg = "Invalid environment. Use 'sandbox' or 'production'.";
    if ($OUTPUT_RAW_JSON) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_environment', 'message' => $msg]);
    } else {
        echo $msg . PHP_EOL;
    }
    exit;
}

// Resolve base URL and token
$apiBaseUrl = rtrim($config[$environment]['api_url'], '/');
$apiToken   = $config[$environment]['api_token'];

/**
 * Build endpoint path by API version
 */
$endpoint = ($apiVersion === 'v2') ? '/v2/refunds' : '/refunds';
$apiUrl   = $apiBaseUrl . $endpoint;

/**
 * Inputs for the refund
 * - Provide the depositId of the original successful deposit
 * - Provide the refund amount, for V2 also set the currency
 */
$refundId          = generateUuidV4();
$depositIdToRefund = 'your_production_api_token_here'; // replace with the real depositId to refund
$amount            = '1000';    // use string per API format
$currency          = 'UGX';   // required only for V2 refunds

/**
 * Metadata examples
 * - V1 expects array of objects with fieldName and fieldValue
 * - V2 expects array of objects with your own keys
 */
$metadataV1 = [
    ['fieldName' => 'orderId',    'fieldValue' => 'ORD-123456789'],
    ['fieldName' => 'customerId', 'fieldValue' => 'customer@email.com', 'isPII' => true],
];

$metadataV2 = [
    ['orderId'    => 'ORD-123456789'],
    ['customerId' => 'customer@email.com', 'isPII' => true],
];

/**
 * Build payload per version, do not mix shapes
 */
if ($apiVersion === 'v2') {
    // V2 payload
    $data = [
        'refundId'  => $refundId,
        'depositId' => $depositIdToRefund,
        'amount'    => $amount,
        'currency'  => $currency,
        'metadata'  => $metadataV2,
    ];
} else {
    // V1 payload
    $data = [
        'refundId'  => $refundId,
        'depositId' => $depositIdToRefund,
        'amount'    => $amount,
        'metadata'  => $metadataV1,
    ];
}

/**
 * cURL request, POST JSON to the chosen endpoint
 */
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$apiToken}",
        "Content-Type: application/json",
    ],
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_SLASHES),
    CURLOPT_SSL_VERIFYPEER => ($environment === 'production'), // true in production, often false in local sandbox
    CURLOPT_HEADER         => false,
]);

$response   = curl_exec($ch);
$curlErrNo  = curl_errno($ch);
$curlErrMsg = curl_error($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

/**
 * Handle transport errors and HTTP status codes
 */
if ($curlErrNo) {
    if ($OUTPUT_RAW_JSON) {
        header('Content-Type: application/json');
        echo json_encode([
            'error'   => 'curl_error',
            'code'    => $curlErrNo,
            'message' => $curlErrMsg
        ], JSON_UNESCAPED_SLASHES);
    } else {
        echo "cURL Error {$curlErrNo}: {$curlErrMsg}" . PHP_EOL;
    }
    exit;
}

if ($httpStatus === 200) {
    if ($OUTPUT_RAW_JSON) {
        header('Content-Type: application/json');
        echo $response; // exact body from API
        exit;
    } else {
        $json = json_decode($response, true);
        echo "Refund initiated successfully. Version={$apiVersion}" . PHP_EOL;
        echo "HTTP Status: {$httpStatus}" . PHP_EOL;
        echo "Refund ID: {$refundId}" . PHP_EOL;
        echo "Full Response:" . PHP_EOL;
        print_r($json);
        exit;
    }
} else {
    if ($OUTPUT_RAW_JSON) {
        header('Content-Type: application/json');
        echo json_encode([
            'error'       => 'non_success_status',
            'httpStatus'  => $httpStatus,
            'rawResponse' => $response
        ], JSON_UNESCAPED_SLASHES);
    } else {
        echo "Failed to initiate refund. HTTP {$httpStatus}. Version={$apiVersion}" . PHP_EOL;
        echo "Raw Response: {$response}" . PHP_EOL;
    }
    exit;
}