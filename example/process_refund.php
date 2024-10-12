<?php

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

// Initialize Whoops error handler for development
$whoops = new Run();
$whoops->pushHandler(new PrettyPageHandler());
$whoops->register();

// Load the environment variables from the .env file
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();


// Set the environment and SSL verification based on the production status
$environment = getenv('ENVIRONMENT') ?: 'sandbox'; // Default to sandbox if not specified
$sslVerify = $environment === 'production';  // SSL verification true in production

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

// Create a new instance of the API client with SSL verification control
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify);

// Retrieve and sanitize form data
$depositId = isset($_POST['depositId']) ? trim($_POST['depositId']) : '';
$amount = isset($_POST['amount']) ? trim($_POST['amount']) : '';

// Retrieve metadata fields from the form
$metadataFieldNames = isset($_POST['metadataFieldName']) ? $_POST['metadataFieldName'] : [];
$metadataFieldValues = isset($_POST['metadataFieldValue']) ? $_POST['metadataFieldValue'] : [];
$metadataIsPII = isset($_POST['metadataIsPII']) ? $_POST['metadataIsPII'] : [];

// Ensure that metadata fields are arrays
$metadataFieldNames = is_array($metadataFieldNames) ? $metadataFieldNames : [$metadataFieldNames];
$metadataFieldValues = is_array($metadataFieldValues) ? $metadataFieldValues : [$metadataFieldValues];
$metadataIsPII = is_array($metadataIsPII) ? $metadataIsPII : [$metadataIsPII];

// Construct metadata array
$metadata = [];
for ($i = 0; $i < count($metadataFieldNames); $i++) {
    $fieldName = trim($metadataFieldNames[$i]);
    $fieldValue = trim($metadataFieldValues[$i]);
    $isPII = isset($metadataIsPII[$i]) ? true : false;

    // Skip empty metadata fields
    if ($fieldName === '' || $fieldValue === '') {
        continue;
    }

    $metadataItem = [
        'fieldName' => $fieldName,
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

// Prepare the request payload (keeping it as provided)
$refundId = Helpers::generateUniqueId();  // Generate a valid UUID for the refundId
// Note: The depositId is obtained from the form
// The amount is obtained from the form
// Metadata is constructed from the form inputs

try {
    // Validate required fields
    if (empty($depositId) || empty($amount)) {
        throw new Exception('Missing required fields: Deposit ID and Refund Amount are required.');
    }

    // Validate Deposit ID
    if (!isValidUUIDv4($depositId)) {
        throw new Exception('Invalid Deposit ID format. It must be a valid UUID version 4.');
    }

    // Validate Refund Amount
    if (!is_numeric($amount) || floatval($amount) <= 0) {
        throw new Exception('Invalid Refund Amount. It must be a positive number.');
    }

    // Validate Metadata
    // Ensure predefined metadata fields are modified
    $predefinedMetadata = [
        'orderId' => 'ORD-123456789',
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

    // Prepare the data payload
    $data = [
        'refundId' => $refundId,
        'depositId' => $depositId,
        'amount' => floatval($amount),
        'metadata' => $metadata
    ];

    // Initiate the refund using ApiClient
    $response = $pawaPayClient->initiateRefund($refundId, $depositId, floatval($amount), $metadata);

    // Check the API response status
    if ($response['status'] === 200) {
        // Log refund initiation success
        $log->info('Refund initiated successfully', [
            'refundId' => $refundId,
            'depositId' => $depositId,
            'amount' => $amount,
            'metadata' => $metadata,
            'response' => $response['response']
        ]);

        // Now check the refund status
        try {
            $statusResponse = $pawaPayClient->checkTransactionStatus($refundId, 'refund');

            if ($statusResponse['status'] === 200) {
                // Log refund status retrieval success
                $log->info('Refund status retrieved successfully', [
                    'refundId' => $refundId,
                    'statusResponse' => $statusResponse['response']
                ]);

                // Send success response back to the client with refund status
                echo json_encode([
                    'success' => true,
                    'refundId' => $refundId,
                    'message' => 'Refund initiated and status retrieved successfully.',
                    'refundStatus' => $statusResponse['response']
                ]);
            } else {
                // Log failure to retrieve refund status
                $log->error('Failed to retrieve refund status', [
                    'refundId' => $refundId,
                    'statusResponse' => $statusResponse
                ]);

                // Send response indicating refund initiation success but unable to retrieve status
                echo json_encode([
                    'success' => true,
                    'refundId' => $refundId,
                    'message' => 'Refund initiated successfully, but unable to retrieve refund status.',
                    'error' => 'Unable to retrieve refund status.'
                ]);
            }
        } catch (Exception $e) {
            // Log the error
            $log->error('Error occurred while checking refund status', [
                'refundId' => $refundId,
                'error' => $e->getMessage()
            ]);

            // Send response indicating refund initiation success but error in retrieving status
            echo json_encode([
                'success' => true,
                'refundId' => $refundId,
                'message' => 'Refund initiated successfully, but an error occurred while retrieving refund status.',
                'error' => $e->getMessage()
            ]);
        }
    } else {
        // Log initiation failure
        $log->error('Refund initiation failed', [
            'refundId' => $refundId,
            'depositId' => $depositId,
            'amount' => $amount,
            'metadata' => $metadata,
            'response' => $response
        ]);

        // Extract error message from response
        $errorMessage = isset($response['response']['message']) ? $response['response']['message'] : 'Unknown error occurred.';
        echo json_encode([
            'success' => false,
            'errorMessage' => 'Refund initiation failed: ' . $errorMessage
        ]);
    }
} catch (Exception $e) {
    // Catch validation errors and return the message
    $errorMessage = $e->getMessage();

    // Log the error
    $log->error('Error occurred during refund initiation', [
        'refundId' => $refundId,
        'depositId' => $depositId,
        'amount' => $amount,
        'metadata' => $metadata,
        'error' => $errorMessage
    ]);

    // Send error response back to the client
    echo json_encode([
        'success' => false,
        'errorMessage' => $errorMessage
    ]);
}