<?php


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
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_success.log', \Monolog\Level::Info));
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_failed.log', \Monolog\Level::Error));

// Create a new instance of the API client with SSL verification control
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify);

// Prepare the request payload (data to be sent)
$refundId = Helpers::generateUniqueId();  // Generate a valid UUID for the refundId
$depositId = 'deposit UUID';  // The deposit UUID to be refunded
$amount = 100;  // Refund amount
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
    // Call the `initiateRefund` method from ApiClient
    $response = $pawaPayClient->initiateRefund($refundId, $depositId, $amount, $metadata);

    // Check the API response status
    if ($response['status'] === 200) {
        // Log refund initiation success
        $log->info('Refund initiated successfully', [
            'refundId' => $refundId,
            'response' => $response['response']
        ]);

        // Send success response back to the client
        echo json_encode([
            'success' => true,
            'refundId' => $refundId,
            'message' => 'Refund initiated successfully.'
        ]);
    } else {
        // Log initiation failure
        $log->error('Refund initiation failed', [
            'refundId' => $refundId,
            'response' => $response
        ]);

        // Send error response back to the client
        $errorMessage = isset($response['response']['message']) ? $response['response']['message'] : 'Unknown error occurred.';
        echo json_encode([
            'success' => false,
            'errorMessage' => 'Refund initiation failed: ' . $errorMessage
        ]);
    }
} catch (Exception $e) {
    // Catch any errors and return the message
    $errorMessage = "Error: " . $e->getMessage();

    // Log the error
    $log->error('Error occurred during refund initiation', [
        'refundId' => $refundId,
        'error' => $errorMessage
    ]);

    // Send error response back to the client
    echo json_encode([
        'success' => false,
        'errorMessage' => $errorMessage
    ]);
}