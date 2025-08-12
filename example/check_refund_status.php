<?php

/**
 * Refund status checker - version aware, V1 by default
 *
 * Purpose
 * - Keep one script that works with both V1 and V2 refund status endpoints.
 * - Default to V2, but allow an env flip to V1 for legacy checks.
 *
 * Surgical fixes added
 * - Default version is now V1 (set PAWAPAY_API_VERSION=v2 to force V2).
 * - Added an 'Accept: application/json' header via a custom Guzzle client,
 *   some V1 stacks are picky and may return an empty body without it.
 * - Smart fallback for V1:
 *     1) Call checkTransactionStatusAuto (expected V1 call).
 *     2) If HTTP 200 and empty body, retry with explicit V1 method.
 *     3) If still empty, probe V2 once to help diagnose whether the refundId
 *        belongs to a V2 flow or your V1 base path needs a '/v1' prefix.
 *
 * How versioning works
 * - ENVIRONMENT selects sandbox or production, defaults to sandbox.
 * - PAWAPAY_{ENV}_API_TOKEN provides the token, for example PAWAPAY_SANDBOX_API_TOKEN.
 * - PAWAPAY_API_VERSION selects v1 or v2. Defaults to v2 here.
 * - We call checkTransactionStatusAuto which routes to V1 or V2 based on the client version.
 *
 * Notes
 * - If V1 still returns an empty body after these fixes, update your ApiClient V1 endpoints
 *   to auto-prefix '/v1/...' when the base URL does not already end with '/v1'
 *   (similar to how createPaymentPageSession handles it).
 */

// Include the Composer autoload for dependencies
require_once __DIR__ . '/../vendor/autoload.php';

use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;
use GuzzleHttp\Client as GuzzleClient;

// Initialize Whoops for error handling in development environments
$whoops = new Run();
$whoops->pushHandler(new PrettyPageHandler());
$whoops->register();

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Set the environment and SSL verification based on the production status
$environment = getenv('ENVIRONMENT') ?: 'sandbox'; // Default to sandbox if not specified
$sslVerify   = $environment === 'production';       // SSL verification true in production

/**
 * Version switch - V1 by default
 * - Preserve the ability to use V1 by setting PAWAPAY_API_VERSION=v1
 */
$apiVersion = getenv('PAWAPAY_API_VERSION') ?: 'v1';

// Dynamically construct the API token key
$apiTokenKey = 'PAWAPAY_' . strtoupper($environment) . '_API_TOKEN';

// Get the API token based on the environment
$apiToken = $_ENV[$apiTokenKey] ?? null;

if (!$apiToken) {
    throw new Exception("API token not found for the selected environment");
}

// Initialize Monolog for logging
$log = new Logger('pawaPayLogger');
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_success.log', \Monolog\Level::Info));  // Use \Monolog\Level::Info
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_failed.log', \Monolog\Level::Error));  // Use \Monolog\Level::Error

// Create an API client instance - pass version so auto routing works
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify, $apiVersion);

/**
 * Add an Accept header to improve V1 compatibility.
 * ApiClient already sets Authorization and Content-Type on each request.
 * Guzzle merges these with the client's default headers.
 */
$pawaPayClient->setHttpClient(new GuzzleClient([
    'headers' => ['Accept' => 'application/json'],
]));

// Set the refund ID you want to check (example UUID)
$refundId = '00000000-0000-0000-0000-000000000000';

try {
    /**
     * Call the version-aware status check.
     * - If $apiVersion is v2, this hits /v2/refunds/{id}
     * - If v1, it hits /refunds/{id} (ApiClient's V1 route)
     */
    $response = $pawaPayClient->checkTransactionStatusAuto($refundId, 'refund');

    /**
     * V1 fallback: if HTTP 200 with an empty or null body, try an explicit V1 call.
     * If still empty, probe V2 once to help diagnose.
     */
    if ($apiVersion === 'v1' && isset($response['status']) && (int)$response['status'] === 200) {
        $body = $response['response'] ?? null;
        $isEmpty = ($body === null) || (is_array($body) && count($body) === 0);

        if ($isEmpty) {
            // Retry with explicit V1 method
            $explicitV1 = $pawaPayClient->checkTransactionStatus($refundId, 'refund');
            $explicitBody = $explicitV1['response'] ?? null;
            $explicitEmpty = ($explicitBody === null) || (is_array($explicitBody) && count($explicitBody) === 0);

            if (!$explicitEmpty) {
                $response = $explicitV1; // use the non-empty explicit V1 response
            } else {
                // Last-resort probe: maybe this refund belongs to the V2 flow or the V1 base path needs '/v1'
                $probe = $pawaPayClient->checkTransactionStatusV2($refundId, 'refund');
                $probeBody = $probe['response'] ?? null;
                $probeOk = isset($probe['status']) && (int)$probe['status'] === 200 && $probeBody;

                if ($probeOk) {
                    // Keep the original version context, but surface useful diagnostics
                    $log->warning('V1 returned empty body, V2 probe found data. Check if refundId is V2 or if V1 endpoints need /v1 prefix.', [
                        'refundId' => $refundId,
                        'v2Probe'  => $probeBody,
                    ]);
                    // Show something useful to the caller instead of an empty array
                    $response = [
                        'status'   => 200,
                        'response' => [
                            'note'     => 'V1 returned empty body. Showing V2 probe payload for diagnostics.',
                            'v2Status' => $probeBody,
                        ],
                    ];
                }
            }
        }
    }

    // Handle the response based on status code
    if (isset($response['status']) && (int)$response['status'] === 200) {
        echo "Refund Status [version={$apiVersion}]: " . json_encode($response['response'], JSON_PRETTY_PRINT) . "\n";

        // Log the success
        $log->info('Refund status retrieved successfully', [
            'version'  => $apiVersion,
            'refundId' => $refundId,
            'response' => $response['response']
        ]);
    } else {
        echo "Error: Unable to retrieve refund status [version={$apiVersion}].\n";
        print_r($response);

        // Log the failure
        $log->error('Failed to retrieve refund status', [
            'version'  => $apiVersion,
            'refundId' => $refundId,
            'response' => $response
        ]);
    }
} catch (Exception $e) {
    // Display and log any errors
    echo "Error: " . $e->getMessage() . "\n";
    $log->error('Error occurred', [
        'version'  => $apiVersion,
        'refundId' => $refundId,
        'error'    => $e->getMessage()
    ]);
}