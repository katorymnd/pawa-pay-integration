<?php

/**
 * Refund initiator + status checker (form handler) — dual stack V1/V2, version-aware
 *
 * What this file does
 * - Accepts POSTed refund data, validates it, initiates a refund, then fetches its status.
 * - Adds V2 support surgically while keeping V1 working exactly as before.
 * - Chooses version using PAWAPAY_API_VERSION env var. Defaults to v2 here.
 *
 * Version routing
 * - V2 path: initiateRefundV2 (requires currency), checkTransactionStatusV2
 * - V1 path: initiateRefund, checkTransactionStatus
 * - Script calls initiateRefundAuto + checkTransactionStatusAuto on ApiClient, which route by version.
 *
 * Practical notes
 * - If you submit without a currency and PAWAPAY_API_VERSION=v2, we fall back to 'UGX' so you are never blocked.
 *   You should pass a correct ISO 4217 currency via POST[currency] to match the original deposit.
 * - Metadata is accepted in V1 style (fieldName, fieldValue, isPII?), the client normalizes it for V2.
 * - We add an 'Accept: application/json' header for better V1 compatibility with some stacks.
 *
 * Creative alternatives [brief]
 * - Strict V2 only: require POST[currency] when PAWAPAY_API_VERSION=v2, reject if missing. Safer, but less forgiving during rollout.
 * - Capability probe: call checkActiveConfAuto first and choose V2 only if provider+currency are enabled. Reduces avoidable rejects.
 * - Canary mode: run PAWAPAY_API_VERSION=v2 on staging, keep v1 in production, then flip the env var to cut over safely.
 */

header('Content-Type: application/json');

// Include Composer's autoloader
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    echo json_encode([
        'success' => false,
        'errorMessage' => 'Autoloader not found. Please run composer install.'
    ]);
    exit;
}
require_once $autoloadPath;

use Dotenv\Dotenv;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;
use GuzzleHttp\Client as GuzzleClient;

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
 * API version, V2 by default for forward motion.
 * Set PAWAPAY_API_VERSION=v1 to keep legacy behavior.
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
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/refund_success.log', \Monolog\Level::Info));
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/refund_failed.log', \Monolog\Level::Error));

// Create a new instance of the API client with SSL verification control and version awareness
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify, $apiVersion);

// Improve V1 compatibility on some stacks that require explicit Accept
$pawaPayClient->setHttpClient(new GuzzleClient([
    'headers' => ['Accept' => 'application/json'],
]));

// Retrieve and sanitize form data
$depositId = isset($_POST['depositId']) ? trim($_POST['depositId']) : '';
$amountRaw = isset($_POST['amount']) ? trim($_POST['amount']) : '';
$currency  = isset($_POST['currency']) ? strtoupper(trim($_POST['currency'])) : ''; // optional, needed for V2

// Retrieve metadata fields from the form
$metadataFieldNames  = isset($_POST['metadataFieldName']) ? $_POST['metadataFieldName'] : [];
$metadataFieldValues = isset($_POST['metadataFieldValue']) ? $_POST['metadataFieldValue'] : [];
$metadataIsPII       = isset($_POST['metadataIsPII']) ? $_POST['metadataIsPII'] : [];

// Ensure that metadata fields are arrays
$metadataFieldNames  = is_array($metadataFieldNames)  ? $metadataFieldNames  : [$metadataFieldNames];
$metadataFieldValues = is_array($metadataFieldValues) ? $metadataFieldValues : [$metadataFieldValues];
$metadataIsPII       = is_array($metadataIsPII)       ? $metadataIsPII       : [$metadataIsPII];

// Construct metadata array (V1 style)
$metadata = [];
for ($i = 0; $i < count($metadataFieldNames); $i++) {
    $fieldName  = trim((string)$metadataFieldNames[$i]);
    $fieldValue = trim((string)$metadataFieldValues[$i]);
    $isPII      = isset($metadataIsPII[$i]) ? true : false;

    // Skip empty metadata fields
    if ($fieldName === '' || $fieldValue === '') {
        continue;
    }

    $metadataItem = [
        'fieldName'  => $fieldName,
        'fieldValue' => $fieldValue,
    ];

    if ($isPII) {
        $metadataItem['isPII'] = true;
    }

    $metadata[] = $metadataItem;
}

// Function to validate UUID (version 4)
function isValidUUIDv4($uuid)
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
}

// Function to validate email
function isValidEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Prepare IDs and normalize amount
$refundId = Helpers::generateUniqueId();      // UUID for the refundId
$amount   = (string) (floatval($amountRaw));  // send as string, API tolerates numeric JSON too

try {
    // Validate required fields
    if (empty($depositId) || $amount === '0') {
        throw new Exception('Missing required fields: Deposit ID and Refund Amount are required.');
    }

    // Validate Deposit ID
    if (!isValidUUIDv4($depositId)) {
        throw new Exception('Invalid Deposit ID format. It must be a valid UUID version 4.');
    }

    // Validate Refund Amount
    if (!is_numeric($amountRaw) || floatval($amountRaw) <= 0) {
        throw new Exception('Invalid Refund Amount. It must be a positive number.');
    }

    // Validate Metadata: ensure predefined placeholders are edited
    $predefinedMetadata = [
        'orderId'    => 'ORD-123456789',
        'customerId' => 'customer@email.com'
    ];

    foreach ($metadata as $meta) {
        if (array_key_exists($meta['fieldName'], $predefinedMetadata)) {
            if ($meta['fieldValue'] === $predefinedMetadata[$meta['fieldName']]) {
                throw new Exception("Please update the pre-filled {$meta['fieldName']} value before submitting.");
            }

            // Additional validation for customerId
            if ($meta['fieldName'] === 'customerId' && !isValidEmail($meta['fieldValue'])) {
                throw new Exception("Please enter a valid email address for customerId.");
            }
        }
    }

    // Validate metadata count (maximum 10)
    if (count($metadata) > 10) {
        throw new Exception('You cannot add more than 10 metadata fields.');
    }

    /**
     * Build version-aware payload
     * - V1: refundId, depositId, amount, metadata
     * - V2: refundId, depositId, amount, currency, metadata
     * Fallback: if currency is missing on V2, use 'UGX' to avoid blocking form submissions.
     */
    $args = [
        'refundId'  => $refundId,
        'depositId' => $depositId,
        'amount'    => $amount,
        'metadata'  => $metadata
    ];
    if ($apiVersion === 'v2') {
        $args['currency'] = $currency !== '' ? $currency : 'UGX';
        if ($currency === '') {
            $log->warning('Currency missing for V2 refund, defaulted to UGX. Provide POST[currency] to match deposit currency.', [
                'refundId'  => $refundId,
                'depositId' => $depositId
            ]);
        }
    }

    // Initiate the refund using version-aware call
    $response = $pawaPayClient->initiateRefundAuto($args);

    // Check the API response status
    if ($response['status'] === 200) {
        // Log refund initiation success
        $log->info('Refund initiated successfully', [
            'version'  => $apiVersion,
            'refundId' => $refundId,
            'depositId' => $depositId,
            'amount'   => $amount,
            'currency' => $args['currency'] ?? null,
            'metadata' => $metadata,
            'response' => $response['response']
        ]);

        // Now check the refund status using version-aware call
        try {
            $statusResponse = $pawaPayClient->checkTransactionStatusAuto($refundId, 'refund');

            // V1 edge case: HTTP 200 but empty body, try explicit V1 then probe V2 for diagnostics
            if ($apiVersion === 'v1' && isset($statusResponse['status']) && (int)$statusResponse['status'] === 200) {
                $body = $statusResponse['response'] ?? null;
                $empty = ($body === null) || (is_array($body) && count($body) === 0);
                if ($empty) {
                    $explicitV1 = $pawaPayClient->checkTransactionStatus($refundId, 'refund');
                    $explicitBody = $explicitV1['response'] ?? null;
                    if ($explicitBody) {
                        $statusResponse = $explicitV1;
                    } else {
                        $probe = $pawaPayClient->checkTransactionStatusV2($refundId, 'refund');
                        if (isset($probe['status']) && (int)$probe['status'] === 200 && !empty($probe['response'])) {
                            $log->warning('V1 status empty, V2 probe returned data. Check if refund belongs to V2 flow or if V1 endpoints need /v1 prefix.', [
                                'refundId' => $refundId,
                                'v2Probe'  => $probe['response']
                            ]);
                            $statusResponse = [
                                'status'   => 200,
                                'response' => [
                                    'note'     => 'V1 response body was empty. Showing V2 probe payload for diagnostics.',
                                    'v2Status' => $probe['response'],
                                ]
                            ];
                        }
                    }
                }
            }

            if ($statusResponse['status'] === 200) {
                // Log refund status retrieval success
                $log->info('Refund status retrieved successfully', [
                    'version'        => $apiVersion,
                    'refundId'       => $refundId,
                    'statusResponse' => $statusResponse['response']
                ]);

                // Send success response back to the client with refund status
                echo json_encode([
                    'success'      => true,
                    'version'      => $apiVersion,
                    'refundId'     => $refundId,
                    'message'      => 'Refund initiated and status retrieved successfully.',
                    'refundStatus' => $statusResponse['response']
                ]);
            } else {
                // Log failure to retrieve refund status
                $log->error('Failed to retrieve refund status', [
                    'version'        => $apiVersion,
                    'refundId'       => $refundId,
                    'statusResponse' => $statusResponse
                ]);

                // Send response indicating refund initiation success but unable to retrieve status
                echo json_encode([
                    'success'  => true,
                    'version'  => $apiVersion,
                    'refundId' => $refundId,
                    'message'  => 'Refund initiated successfully, but unable to retrieve refund status.',
                    'error'    => 'Unable to retrieve refund status.'
                ]);
            }
        } catch (Exception $e) {
            // Log the error
            $log->error('Error occurred while checking refund status', [
                'version'  => $apiVersion,
                'refundId' => $refundId,
                'error'    => $e->getMessage()
            ]);

            // Send response indicating refund initiation success but error in retrieving status
            echo json_encode([
                'success'  => true,
                'version'  => $apiVersion,
                'refundId' => $refundId,
                'message'  => 'Refund initiated successfully, but an error occurred while retrieving refund status.',
                'error'    => $e->getMessage()
            ]);
        }
    } else {
        // Log initiation failure with richer details if present
        $failureMessage = $response['response']['failureReason']['failureMessage'] ?? null;
        $failureCode    = $response['response']['failureReason']['failureCode'] ?? null;
        $genericMessage = $response['response']['message'] ?? null;

        $errorMessage = $failureMessage ?: ($genericMessage ?: 'Unknown error occurred.');

        $log->error('Refund initiation failed', [
            'version'   => $apiVersion,
            'refundId'  => $refundId,
            'depositId' => $depositId,
            'amount'    => $amount,
            'currency'  => $args['currency'] ?? null,
            'metadata'  => $metadata,
            'failureCode' => $failureCode,
            'response'  => $response
        ]);

        echo json_encode([
            'success'      => false,
            'version'      => $apiVersion,
            'errorMessage' => 'Refund initiation failed: ' . $errorMessage,
            'failureCode'  => $failureCode
        ]);
    }
} catch (Exception $e) {
    // Catch validation errors and return the message
    $errorMessage = $e->getMessage();

    // Log the error
    $log->error('Error occurred during refund initiation', [
        'version'   => $apiVersion,
        'refundId'  => $refundId ?? null,
        'depositId' => $depositId ?? null,
        'amount'    => $amount ?? null,
        'metadata'  => $metadata ?? null,
        'error'     => $errorMessage
    ]);

    // Send error response back to the client
    echo json_encode([
        'success'      => false,
        'version'      => $apiVersion,
        'errorMessage' => $errorMessage
    ]);
}