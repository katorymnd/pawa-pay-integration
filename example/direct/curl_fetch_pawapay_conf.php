<?php
/**
 * PawaPay Config and Availability Fetcher, V1 or V2 selectable
 *
 * Purpose
 * Fetch raw JSON from either:
 *  - /active-conf or /v2/active-conf  your account configuration
 *  - /availability or /v2/availability current provider availability by country and operation
 *
 * How to use
 * 1) Set $environment to 'sandbox' or 'production'.
 * 2) Set $apiVersion to 'v1' or 'v2'. Default is 'v1' to match the prior script behavior.
 * 3) Set $endpointType to 'active-conf' or 'availability'.
 * 4) Optionally set $country to an ISO 3166-1 alpha-3 code, and $operationType to one of
 *    'DEPOSIT', 'PAYOUT', 'REFUND', 'REMITTANCE'. These filters are applied to the availability endpoint
 *    for both v1 and v2. If the server ignores unsupported filters, it will safely return unfiltered data.
 *
 * Output
 * Prints the raw JSON response when HTTP 200, otherwise prints the status code and response body.
 *
 * Notes
 * SSL peer verification is disabled here for local testing convenience. Enable it in production.
 */

// -------------------- Environment Configuration --------------------
$config = [
    'sandbox' => [
        'api_url'   => 'https://api.sandbox.pawapay.io',
        'api_token' => 'your_sandbox_api_token'
    ],
    'production' => [
        'api_url'   => 'https://api.pawapay.io',
        'api_token' => 'your_production_api_token'
    ]
];

// -------------------- Choose Environment, Version, Endpoint --------------------
$environment  = 'sandbox';          // 'sandbox' or 'production'
$apiVersion   = 'v1';               // 'v1' or 'v2'  default v1 to preserve current usage
$endpointType = 'active-conf';      // 'active-conf' or 'availability'

// Optional filters for availability
$country       = null;              // e.g. 'UGA' or null
$operationType = null;              // 'DEPOSIT','PAYOUT','REFUND','REMITTANCE' or null

// -------------------- Validate environment --------------------
if (!isset($config[$environment])) {
    die("Invalid environment selected. Choose 'sandbox' or 'production'.");
}

$apiBaseUrl = rtrim($config[$environment]['api_url'], '/');
$apiToken   = $config[$environment]['api_token'];

// -------------------- Resolve endpoint path per version --------------------
/**
 * Map endpoint by version and type.
 * v1: /active-conf, /availability
 * v2: /v2/active-conf, /v2/availability
 */
if ($endpointType === 'availability') {
    $path = ($apiVersion === 'v2') ? '/v2/availability' : '/availability';
} else {
    $endpointType = 'active-conf';
    $path = ($apiVersion === 'v2') ? '/v2/active-conf' : '/active-conf';
}

$apiUrl = $apiBaseUrl . $path;

// -------------------- Attach query params for availability on both versions --------------------
if ($endpointType === 'availability') {
    $query = [];
    if (!empty($country)) {
        $query['country'] = $country;
    }
    if (!empty($operationType)) {
        $query['operationType'] = $operationType;
    }
    if (!empty($query)) {
        $apiUrl .= '?' . http_build_query($query);
    }
}

// -------------------- cURL --------------------
$curl = curl_init($apiUrl);

curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$apiToken}",
    "Content-Type: application/json"
]);

// SSL verification
// For production usage, set this to true or remove the line to use default verification.
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

// Execute request
$response = curl_exec($curl);

// Transport errors
if (curl_errno($curl)) {
    echo "cURL Error: " . curl_error($curl);
    curl_close($curl);
    exit();
}

// HTTP status code
$httpStatusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

// Close cURL
curl_close($curl);

// -------------------- Output --------------------
if ($httpStatusCode === 200) {
    header('Content-Type: application/json');
    echo $response;
} else {
    echo "Failed to fetch {$endpointType} [version={$apiVersion}]. HTTP Status Code: {$httpStatusCode}\n";
    echo "Response: " . $response;
}