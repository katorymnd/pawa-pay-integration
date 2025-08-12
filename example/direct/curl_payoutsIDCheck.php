<?php
/**
 * pawaPay Payout Status Checker, V1 or V2 selectable
 *
 * What this script does
 * - Queries payout status by payoutId using either:
 *     V1: GET /payouts/{payoutId}
 *     V2: GET /v2/payouts/{payoutId}
 * - Lets you switch environment (sandbox, production) and API version (v1, v2) surgically.
 * - Prints a JSON response with success flag, message, and the raw API payload or error details.
 *
 * How to use
 * - Set $environment to 'sandbox' or 'production'.
 * - Set $apiVersion to 'v1' [default] or 'v2'.
 * - Set $payoutId to the UUID you want to check.
 *
 * Notes
 * - We ignore any path fragments baked into config['api_url'] and always build the correct path for the chosen version.
 * - SSL peer verification is enabled automatically for production, and disabled for sandbox to ease local testing.
 * - If you prefer absolutely raw API JSON, you can adapt the echo section, but this wrapper keeps a stable structure.
 */

// -----------------------------------------------------------------------------
// Configuration for the environment (sandbox or production)
// -----------------------------------------------------------------------------
$config = [
    'sandbox' => [
        // May include a path, we will normalize to the host below
        'api_url'  => 'https://api.sandbox.pawapay.io/payouts/',
        'api_token'=> 'your_sandbox_api_token'
    ],
    'production' => [
        'api_url'  => 'https://api.pawapay.io/payouts/',
        'api_token'=> 'your_production_api_token'
    ]
];

// -----------------------------------------------------------------------------
// Select environment and API version
// -----------------------------------------------------------------------------
$environment = 'sandbox'; // 'sandbox' or 'production'
$apiVersion  = 'v1';      // 'v1' [default] or 'v2'

// Validate environment
if (!isset($config[$environment])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => "Invalid environment selected. Please choose 'sandbox' or 'production'."
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// Settings, token, and robust base URL resolver
// -----------------------------------------------------------------------------
$configuredUrl = rtrim($config[$environment]['api_url'], '/');
$apiToken      = $config[$environment]['api_token'];

/**
 * Extract scheme://host from a possibly path-suffixed URL.
 * Falls back to the configured string if parsing fails.
 *
 * @param string $url
 * @return string
 */
function resolveBaseHost(string $url): string
{
    $parts = parse_url($url);
    if (!empty($parts['scheme']) && !empty($parts['host'])) {
        $host = $parts['scheme'] . '://' . $parts['host'];
        // Preserve port if present
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        return $host;
    }
    return $url;
}

$baseHost = resolveBaseHost($configuredUrl);

// -----------------------------------------------------------------------------
// Build endpoint per version, then append payoutId
// -----------------------------------------------------------------------------
/**
 * Build the versioned path for payout status.
 *
 * @param string $version
 * @param string $payoutId
 * @return string
 */
function buildPayoutStatusPath(string $version, string $payoutId): string
{
    if ($version === 'v2') {
        return '/v2/payouts/' . rawurlencode($payoutId);
    }
    // default v1
    return '/payouts/' . rawurlencode($payoutId);
}

// The payout ID for which the status needs to be checked
$payoutId = "example/direct/curl_transactionIdCheck_test.php";  // Replace with the payout ID you want to check

$apiUrl = $baseHost . buildPayoutStatusPath($apiVersion, $payoutId);

// -----------------------------------------------------------------------------
// Prepare the cURL session for a GET request
// -----------------------------------------------------------------------------
$curl = curl_init();

// Set cURL options
curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ],
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_SSL_VERIFYPEER => ($environment === 'production') ? true : false, // Enable SSL verification in production
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
]);

// Execute the request and capture the response
$response = curl_exec($curl);

// Handle any cURL errors
if (curl_errno($curl)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'version' => $apiVersion,
        'message' => "cURL Error: " . curl_error($curl)
    ]);
} else {
    // Get HTTP status code of the request
    $httpStatusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    // Decode the JSON response
    $decodedResponse = json_decode($response, true);

    // Process the response based on the HTTP status code
    if ($httpStatusCode === 200) {
        // Successful response
        echo json_encode([
            'success' => true,
            'version' => $apiVersion,
            'message' => "Payout status retrieved successfully!",
            'data'    => $decodedResponse
        ], JSON_PRETTY_PRINT);
    } else {
        // Failed response
        echo json_encode([
            'success' => false,
            'version' => $apiVersion,
            'message' => "Failed to retrieve payout status. HTTP Status Code: $httpStatusCode",
            'error'   => $decodedResponse ?: $response
        ], JSON_PRETTY_PRINT);
    }
}

// Close the cURL session
curl_close($curl);