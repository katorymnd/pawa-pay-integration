<?php

header('Content-Type: application/json');

// Include Composer's autoloader
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
require_once $autoloadPath;

use Dotenv\Dotenv;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers;
use Katorymnd\PawaPayIntegration\Utils\Validator;
use Katorymnd\PawaPayIntegration\Utils\FailureCodeHelper;
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

// Retrieve and validate form data
$amount = isset($_POST['amount']) ? trim($_POST['amount']) : '';
$mno = isset($_POST['mno']) ? trim($_POST['mno']) : '';

$payerMsisdn = isset($_POST['payerMsisdn']) ? preg_replace('/\D/', '', trim($_POST['payerMsisdn'])) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$currency = isset($_POST['currency']) ? trim($_POST['currency']) : '';

// Validate input data
if (empty($amount) || empty($mno) || empty($payerMsisdn) || empty($description) || empty($currency)) {
    echo json_encode([
        'success' => false,
        'errorMessage' => 'Missing required fields.'
    ]);
    exit;
}

// Generate a unique deposit ID using a helper method (UUID v4)
$depositId = Helpers::generateUniqueId();

// Prepare metadata if needed (up to 10 items allowed)
$metadata = [];

try {
    // Step 1: Validate the amount using Symfony validation and custom validation
    $validatedAmount = Validator::symfonyValidateAmount($amount);  // Symfony Validator for amount

    // Step 2: Use the Validator to check if the description is valid (alphanumeric and length)
    $validatedDescription = Validator::validateStatementDescription($description);

    // Step 3: Validate the number of metadata items only if metadata is provided
    if (!empty($metadata)) {
        Validator::validateMetadataItemCount($metadata);
    }

    // Step 4: Initiate the deposit using the submitted details
    $response = $pawaPayClient->initiateDeposit(
        $depositId,
        $validatedAmount,
        $currency,
        $mno,
        $payerMsisdn,
        $validatedDescription,
        $metadata
    );

    if ($response['status'] === 200) {
        // Log initiation success
        $log->info('Deposit initiated successfully', [
            'depositId' => $depositId,
            'response' => $response['response']
        ]);

        // Proceed to check the transaction status
        $statusResponse = $pawaPayClient->checkTransactionStatus($depositId, 'deposit');

        if ($statusResponse['status'] === 200) {
            $depositInfo = $statusResponse['response'][0]; // Get the deposit info
            $depositStatus = $depositInfo['status'];

            if ($depositStatus === 'COMPLETED') {
                // Log successful deposit
                $log->info('Deposit completed successfully', [
                    'depositId' => $depositId,
                    'response' => $depositInfo
                ]);

                // Send success response back to JavaScript
                echo json_encode([
                    'success' => true,
                    'transactionId' => $depositId,
                    'message' => 'Payment processed successfully.'
                ]);
            } elseif ($depositStatus === 'FAILED') {
                // Deposit failed
                $failureReason = $depositInfo['failureReason'];
                $failureCode = $failureReason['failureCode'];
                $failureMessage = FailureCodeHelper::getFailureMessage($failureCode);

                $log->error('Deposit failed', [
                    'depositId' => $depositId,
                    'failureCode' => $failureCode,
                    'failureMessage' => $failureMessage,
                    'response' => $depositInfo
                ]);

                // Send error response back to JavaScript
                echo json_encode([
                    'success' => false,
                    'errorMessage' => 'Payment failed: ' . $failureMessage
                ]);
            } else {
                // Deposit is pending or in another state
                $log->info('Deposit is in state: ' . $depositStatus, [
                    'depositId' => $depositId,
                    'response' => $depositInfo
                ]);

                // Send pending response back to JavaScript
                echo json_encode([
                    'success' => false,
                    'errorMessage' => 'Payment is processing. Please wait and check your account.'
                ]);
            }
        } else {
            // Failed to retrieve deposit status
            $log->error('Failed to retrieve deposit status', [
                'depositId' => $depositId,
                'response' => $statusResponse
            ]);
            echo json_encode([
                'success' => false,
                'errorMessage' => 'Unable to retrieve deposit status.'
            ]);
        }
    } else {
        // Log initiation failure
        $log->error('Deposit initiation failed', [
            'depositId' => $depositId,
            'response' => $response
        ]);

        // Send error response back to JavaScript
        echo json_encode([
            'success' => false,
            'errorMessage' => 'Payment initiation failed: ' . $response['response']['message']
        ]);
    }
} catch (Exception $e) {
    // Catch validation errors and display the message
    $errorMessage = "Validation Error: " . $e->getMessage();

    // Log the validation error
    $log->error('Validation error occurred', [
        'depositId' => $depositId,
        'error' => $errorMessage
    ]);

    // Send error response back to JavaScript
    echo json_encode([
        'success' => false,
        'errorMessage' => $errorMessage
    ]);
}