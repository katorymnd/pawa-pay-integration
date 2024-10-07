<?php

// Print out the path to the Composer autoloader
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
require_once $autoloadPath;

use Dotenv\Dotenv;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers;
use Katorymnd\PawaPayIntegration\Utils\Validator;
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

// Set the environment (sandbox or production)
$environment = 'sandbox'; // Change to 'production' when needed
$sslVerify = $environment === 'production';  // SSL verification true in production

// Get the API token based on the environment
$apiToken = $environment === 'sandbox'
    ? $_ENV['PAWAPAY_SANDBOX_API_TOKEN']
    : $_ENV['PAWAPAY_PRODUCTION_API_TOKEN'];

if (!$apiToken) {
    throw new Exception("API token not found for the selected environment");
}

// Initialize Monolog for logging
$log = new Logger('pawaPayLogger');
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_success.log', \Monolog\Level::Info));
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_failed.log', \Monolog\Level::Error));

// Create a new instance of the API client with SSL verification control
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify);

// Generate a unique deposit ID using a helper method (UUID v4)
$depositId = Helpers::generateUniqueId();

// Prepare request details
$amount = '690'; // Amount in UGX or another currency (should be validated)
$currency = 'XOF'; // Currency code
$correspondent = 'MTN_MOMO_BEN'; // Correspondent ID (MTN Uganda)
$payerMsisdn = '22951345069'; // Payer's phone number

// Custom statement description (should be validated)
$customDescription = 'Payment for order 4566'; // update this desc

// Check if metadata is defined, otherwise default to an empty array
$metadata = isset($metadata) ? $metadata : [];


// Prepare metadata - up to 10 items allowed
/**
 * You can optionally include metadata to provide additional details for the transaction.
 * This can be useful for tracking orders, customer information, product IDs, etc.
 *
 * The following example illustrates how to structure the metadata array.
 * Each metadata item consists of:
 * - 'fieldName': The name of the field (e.g., 'orderId', 'customerId').
 * - 'fieldValue': The value corresponding to the field (e.g., 'ORD-123456789', 'John Doe').
 * - 'isPII' (optional): A boolean flag to mark Personally Identifiable Information (PII).
 *
 * Ensure the metadata array contains no more than 10 items. If metadata is omitted, the request will proceed without it.
 *
 * Example structure:
 *
 * $metadata = [
 *     [
 *         'fieldName' => 'orderId',
 *         'fieldValue' => 'ORD-123456789'
 *     ],
 *     [
 *         'fieldName' => 'customerId',
 *         'fieldValue' => 'CUSTOMER-987654321',
 *         'isPII' => true  // Optional: Mark as PII
 *     ],
 *     [
 *         'fieldName' => 'transactionReference',
 *         'fieldValue' => 'TXN-67890'
 *     ],
 *     [
 *         'fieldName' => 'customerEmail',
 *         'fieldValue' => 'customer@example.com',
 *         'isPII' => true  // Optional: Mark as PII
 *     ],
 *     // Add up to 10 metadata items in total
 * ];
 *
 * You can skip metadata entirely by not defining the array, or pass an empty array:
 * $metadata = [];
 */

// $metadata = [
//     [
//         'fieldName' => 'orderId',
//         'fieldValue' => 'ORD-123456789'
//     ],
//     [
//         'fieldName' => 'customerId',
//         'fieldValue' => 'CUSTOMER-987654321',
//         'isPII' => true
//     ],
//     [
//         'fieldName' => 'transactionReference',
//         'fieldValue' => 'TXN-67890'
//     ],
//     [
//         'fieldName' => 'customerEmail',
//         'fieldValue' => 'customer@example.com',
//         'isPII' => true
//     ],
//     [
//         'fieldName' => 'productId',
//         'fieldValue' => 'PROD-9999'
//     ],
//     [
//         'fieldName' => 'shippingAddress',
//         'fieldValue' => '123 Main St, Kampala, Uganda',
//         'isPII' => true
//     ],
//     [
//         'fieldName' => 'paymentMethod',
//         'fieldValue' => 'Mobile Money'
//     ],
//     [
//         'fieldName' => 'customNote',
//         'fieldValue' => 'Urgent order, handle with care'
//     ],
//     [
//         'fieldName' => 'campaignCode',
//         'fieldValue' => 'CAMPAIGN-2024'
//     ],
//     [
//         'fieldName' => 'customerName',
//         'fieldValue' => 'John Doe',
//         'isPII' => true  // Mark as PII
//     ],
// ];

try {
    // Step 1: Validate the amount using Symfony validation and custom validation
    $validatedAmount = Validator::symfonyValidateAmount($amount);  // Symfony Validator for amount

    // Step 2: Use the Validator to check if the description is valid (alphanumeric and length)
    $validatedDescription = Validator::validateStatementDescription($customDescription);

    // Step 3: Validate the number of metadata items only if metadata is provided
    if (!empty($metadata)) {
        Validator::validateMetadataItemCount($metadata);
    }

    // Step 4: If all valid, initiate the deposit, including metadata (only if provided)
    $response = $pawaPayClient->initiateDeposit($depositId, $validatedAmount, $currency, $correspondent, $payerMsisdn, $validatedDescription, $metadata);

    // Check the response status
    if ($response['status'] === 200) {
        echo "Deposit initiated successfully!\n";
        print_r($response['response']);

        // Log success
        $log->info('Deposit initiated successfully', [
            'depositId' => $depositId,
            'response' => $response['response']
        ]);
    } else {
        echo "Error: Unable to initiate deposit.\n";
        print_r($response);

        // Log failure
        $log->error('Deposit initiation failed', [
            'depositId' => $depositId,
            'response' => $response
        ]);
    }
} catch (Exception $e) {
    // Catch validation errors and display the message
    echo "Validation Error: " . $e->getMessage() . "\n";

    // Log the validation error
    $log->error('Validation error occurred', [
        'depositId' => $depositId,
        'error' => $e->getMessage()
    ]);
}