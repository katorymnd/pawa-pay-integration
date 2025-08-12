<?php
/**
 * Refund Status via cURL, supports V1 and V2 surgically
 *
 * What this does
 * * Checks refund status from pawaPay using either V1 (/refunds/{refundId}) or V2 (/v2/refunds/{refundId})
 * * Switch by one variable, $apiVersion, default v1 to preserve old behavior
 * * Either prints exact raw JSON from pawaPay or a normalized JSON wrapper
 *
 * Quick start
 * * Set $environment to sandbox or production
 * * Paste your API tokens into $config
 * * Set $apiVersion to v1 or v2
 * * Set $refundId to the refund you want to check
 * * Run the script from CLI or web
 *
 * Output modes
 * * $OUTPUT_RAW_JSON = true prints the exact response body from pawaPay for HTTP 200
 * * If non 200 or on error, a minimal JSON error object is returned so the output is still valid JSON
 *
 * Notes
 * * V1 returns a JSON array with at most one refund object
 * * V2 returns an object with {"status":"FOUND"|"NOT_FOUND","data":{...}} when 200
 * * SSL peer verification is enabled for production and disabled for sandbox in this example
 * * Signature headers are not required unless you enabled signed requests in your dashboard
 */

/** Toggle raw JSON passthrough */
$OUTPUT_RAW_JSON = true;

/** Environment configuration */
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

/** Choose environment and API version */
$environment = 'sandbox'; // sandbox or production
$apiVersion  = 'v1';      // v1 or v2

/** Refund to check */
$refundId = '00000000-0000-0000-0000-000000000000'; // replace with a real refundId

/** Validate environment */
if (!isset($config[$environment])) {
    header('Content-Type: application/json');
    echo json_encode([
        'error'   => 'invalid_environment',
        'message' => "Invalid environment. Use 'sandbox' or 'production'."
    ]);
    exit;
}

/** Resolve base URL and token */
$apiBaseUrl = rtrim($config[$environment]['api_url'], '/');
$apiToken   = $config[$environment]['api_token'];

/** Build endpoint path by API version */
$endpoint = ($apiVersion === 'v2')
    ? '/v2/refunds/' . rawurlencode($refundId)
    : '/refunds/'    . rawurlencode($refundId);

$apiUrl = $apiBaseUrl . $endpoint;

/** Prepare cURL GET */
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$apiToken}",
        "Content-Type: application/json",
    ],
    CURLOPT_CUSTOMREQUEST  => 'GET',
    CURLOPT_SSL_VERIFYPEER => ($environment === 'production'),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HEADER         => false,
]);

$response   = curl_exec($ch);
$curlErrNo  = curl_errno($ch);
$curlErrMsg = curl_error($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

/** Transport errors */
if ($curlErrNo) {
    header('Content-Type: application/json');
    echo json_encode([
        'error'   => 'curl_error',
        'code'    => $curlErrNo,
        'message' => $curlErrMsg
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/** HTTP handling */
if ($httpStatus === 200) {
    if ($OUTPUT_RAW_JSON) {
        header('Content-Type: application/json');
        echo $response; // exact body from API
        exit;
    }

    // Normalized wrapper for readability
    $body = json_decode($response, true);

    if ($apiVersion === 'v2') {
        // V2 shape: { status: FOUND|NOT_FOUND, data: {...} }
        $found = isset($body['status']) && $body['status'] === 'FOUND';
        header('Content-Type: application/json');
        echo json_encode([
            'success'      => true,
            'apiVersion'   => 'v2',
            'httpStatus'   => $httpStatus,
            'found'        => $found,
            'refundId'     => $refundId,
            'data'         => $body['data'] ?? null,
            'rawResponse'  => $body
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    } else {
        // V1 shape: array with at most one object
        $item  = null;
        if (is_array($body) && !empty($body)) {
            $item = $body[0];
        }
        header('Content-Type: application/json');
        echo json_encode([
            'success'      => true,
            'apiVersion'   => 'v1',
            'httpStatus'   => $httpStatus,
            'found'        => (bool) $item,
            'refundId'     => $refundId,
            'data'         => $item,
            'rawResponse'  => $body
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/** Non 200 response */
header('Content-Type: application/json');
echo json_encode([
    'error'       => 'non_success_status',
    'apiVersion'  => $apiVersion,
    'httpStatus'  => $httpStatus,
    'rawResponse' => $response
], JSON_UNESCAPED_SLASHES);
exit;