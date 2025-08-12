<?php

/**
 * Refund initiation runner - version aware, V2 by default
 *
 * What this does
 * - Adds dual stack support so you can initiate refunds using V1 or V2.
 * - Chooses the version from PAWAPAY_API_VERSION. Defaults to v2 for forward motion.
 * - Uses initiateRefundAuto on ApiClient, which routes to the correct endpoint.
 *
 * How to flip versions
 * - Set PAWAPAY_API_VERSION=v1 to force legacy V1 flow.
 * - Leave it unset or set v2 to use V2 with currency support.
 *
 * Creative, logical alternatives (brief)
 * - Explicit methods: call initiateRefund for V1 or initiateRefundV2 for V2. Good for debugging and demos.
 * - Capability probe: call checkActiveConfAuto first, route to V2 only when provider supports your currency. Reduces avoidable rejects.
 * - Safe gradual cutover: keep PAWAPAY_API_VERSION=v1 in production, run v2 in staging, then flip a single env var to go live.
 *
 * Why this approach helps compared to a single version script
 * - One file, two behaviors. Easier maintenance, fewer merge headaches.
 * - Env driven. No code deploy to switch versions, faster rollbacks, safer experiments.
 * - V2 adds currency and clearer failureReason fields which improve observability and accuracy.
 */

// Include Composer's autoloader
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
require_once $autoloadPath;

use Dotenv\Dotenv;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;

// Initialize Whoops error handler for development
$whoops = new Run();
$whoops->pushHandler(new PrettyPageHandler());
$whoops->register();

// Load the environment variables from the .env file
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Set the environment and SSL verification based on the production status
$environment = getenv('ENVIRONMENT') ?: 'sandbox'; // Default to sandbox if not specified
$sslVerify   = $environment === 'production';       // SSL verification true in production

/**
 * Choose API version
 * - Default to v2 here. Set PAWAPAY_API_VERSION=v1 to force legacy behavior.
 */
$apiVersion = getenv('PAWAPAY_API_VERSION') ?: 'v1';

// Dynamically construct the API token key
$apiTokenKey = 'PAWAPAY_' . strtoupper($environment) . '_API_TOKEN';

// Get the API token based on the environment
$apiToken = $_ENV[$apiTokenKey] ?? null;

if (!$apiToken) {
    echo json_encode([
        'success' => false,
        'errorMessage' => 'API token not found for the selected environment.'
    ]);
    exit;
}

// Initialize Monolog for logging
$log = new Logger('pawaPayLogger');
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_success.log', \Monolog\Level::Info));
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_failed.log', \Monolog\Level::Error));

// Create a new instance of the API client with SSL verification control and version awareness
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify, $apiVersion);

// Prepare the request payload (data to be sent)
$refundId  = Helpers::generateUniqueId();  // Generate a valid UUID for the refundId
$depositId = '00000000-0000-0000-0000-000000000000';               // The deposit UUID to be refunded
$amount    = 1000;                          // Refund amount
$currency  = 'UGX';                        // Used by V2. Keep your real ISO 4217 code here.

$metadata = [
    [
        'fieldName' => 'orderId',
        'fieldValue' => 'ORD-123456789'
    ],
    [
        'fieldName' => 'customerId',
        'fieldValue' => 'customer@email.com',
        'isPII' => true
    ]
];

// Attempt to initiate the refund
try {
    /**
     * Build args for version aware call.
     * - V1: refundId, depositId, amount, metadata
     * - V2: refundId, depositId, amount, currency, metadata
     * We pass amount as string to satisfy strict APIs.
     */
    $args = [
        'refundId'  => $refundId,
        'depositId' => $depositId,
        'amount'    => (string)$amount,
        'metadata'  => $metadata
    ];
    if ($apiVersion === 'v2') {
        $args['currency'] = $currency;
    }

    // Call the version aware refund initiation
    $response = $pawaPayClient->initiateRefundAuto($args);

    // Check the API response status
    if ($response['status'] === 200) {
        // Log refund initiation success
        $log->info('Refund initiated successfully', [
            'version'  => $apiVersion,
            'refundId' => $refundId,
            'response' => $response['response']
        ]);

        // Send success response back to the client
        echo json_encode([
            'success'  => true,
            'version'  => $apiVersion,
            'refundId' => $refundId,
            'message'  => 'Refund initiated successfully.'
        ]);
    } else {
        // Extract richer error detail when available
        $failureMessage = $response['response']['failureReason']['failureMessage'] ?? null;
        $failureCode    = $response['response']['failureReason']['failureCode'] ?? null;
        $genericMessage = $response['response']['message'] ?? null;

        $errorMessage = $failureMessage
            ?: ($genericMessage ?: 'Unknown error occurred.');

        // Log initiation failure
        $log->error('Refund initiation failed', [
            'version'      => $apiVersion,
            'refundId'     => $refundId,
            'failureCode'  => $failureCode,
            'response'     => $response
        ]);

        // Send error response back to the client
        echo json_encode([
            'success'      => false,
            'version'      => $apiVersion,
            'errorMessage' => 'Refund initiation failed: ' . $errorMessage,
            'failureCode'  => $failureCode
        ]);
    }
} catch (Exception $e) {
    // Catch any errors and return the message
    $errorMessage = "Error: " . $e->getMessage();

    // Log the error
    $log->error('Error occurred during refund initiation', [
        'version'  => $apiVersion,
        'refundId' => $refundId,
        'error'    => $errorMessage
    ]);

    // Send error response back to the client
    echo json_encode([
        'success'      => false,
        'version'      => $apiVersion,
        'errorMessage' => $errorMessage
    ]);
}