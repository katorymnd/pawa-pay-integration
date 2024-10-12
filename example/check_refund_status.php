<?php

// Include the Composer autoload for dependencies
require_once __DIR__ . '/../vendor/autoload.php';

use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;

// Initialize Whoops for error handling in development environments
$whoops = new Run();
$whoops->pushHandler(new PrettyPageHandler());
$whoops->register();

// Load environment variables
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
    throw new Exception("API token not found for the selected environment");
}

// Initialize Monolog for logging
$log = new Logger('pawaPayLogger');
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_success.log', \Monolog\Level::Info)); // Use \Monolog\Level::Info
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_failed.log', \Monolog\Level::Error)); // Use \Monolog\Level::Error

// Create an API client instance
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify);

// Manually set the refund ID
$refundId = 'manual_refund_id_here';  // Replace 'manual_refund_id_here' with the actual refund ID

try {
    // Check the refund transaction status
    $response = $pawaPayClient->checkTransactionStatus($refundId, 'refund');

    // Handle the response based on status code
    if ($response['status'] === 200) {
        echo "Refund Status: " . json_encode($response['response'], JSON_PRETTY_PRINT) . "\n";

        // Log the success
        $log->info('Refund status retrieved successfully', [
            'refundId' => $refundId,
            'response' => $response['response']
        ]);
    } else {
        echo "Error: Unable to retrieve refund status.\n";
        print_r($response);

        // Log the failure
        $log->error('Failed to retrieve refund status', [
            'refundId' => $refundId,
            'response' => $response
        ]);
    }
} catch (Exception $e) {
    // Display and log any errors
    echo "Error: " . $e->getMessage() . "\n";
    $log->error('Error occurred', [
        'refundId' => $refundId,
        'error' => $e->getMessage()
    ]);
}