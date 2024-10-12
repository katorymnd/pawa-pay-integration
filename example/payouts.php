<?php

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

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
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_success.log', \Monolog\Level::Info));
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_failed.log', \Monolog\Level::Error));

// Create a new instance of the API client with SSL verification control
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify);

// Get the raw POST data (JSON) sent from the JavaScript fetch request
$data = json_decode(file_get_contents('php://input'), true);

if ($data) {
    // Access the form data in the $data array
    $recipientsData = isset($data['recipients']) ? $data['recipients'] : [];

    $responses = []; // To collect responses for each recipient

    foreach ($recipientsData as $index => $recipientData) {
        try {
            // Prepare recipient data
            $recipient = [
                'payoutId' => Helpers::generateUniqueId(), // Generate a unique payout ID for each recipient
                'amount' => $recipientData['amount'],
                'currency' => $recipientData['currency'],
                'correspondent' => $recipientData['correspondent'],
                'recipientMsisdn' => $recipientData['recipientMsisdn'],
                'statementDescription' => $recipientData['statementDescription'],
            ];

            // Prepare metadata if available
            $metadata = isset($recipientData['metadata']) ? $recipientData['metadata'] : [];

            // Validate the amount using Symfony validation
            $validatedAmount = Validator::symfonyValidateAmount($recipient['amount']);

            // Validate the description for each recipient
            $validatedDescription = Validator::validateStatementDescription($recipient['statementDescription']);

            // Initiate the payout for each recipient
            $initiateResponse = $pawaPayClient->initiatePayout(
                $recipient['payoutId'],
                $validatedAmount,
                $recipient['currency'],
                $recipient['correspondent'],
                $recipient['recipientMsisdn'],
                $validatedDescription,
                $metadata
            );

            // Simulate a short delay
            sleep(2); // Reduced delay for better performance

            // Check the payout status
            $statusResponse = $pawaPayClient->checkTransactionStatus($recipient['payoutId'], 'payout');

            // Prepare the final response based on the payout status
            if ($statusResponse['status'] === 200 && isset($statusResponse['response'][0]['status']) && $statusResponse['response'][0]['status'] === 'COMPLETED') {
                // Payout completed successfully
                $responses[] = [
                    'recipientMsisdn' => $recipient['recipientMsisdn'],
                    'success' => true,
                    'details' => sprintf(
                        'Payout of %s %s to %s was completed successfully with Payout ID: %s.',
                        $validatedAmount,
                        $recipient['currency'],
                        $recipient['recipientMsisdn'],
                        $recipient['payoutId']
                    ),
                    'response' => $statusResponse['response']
                ];
                $log->info('Payout completed successfully', [
                    'payoutId' => $recipient['payoutId'],
                    'response' => $statusResponse['response']
                ]);
            } else {
                // Payout failed
                $failureReason = $statusResponse['response'][0]['failureReason']['failureMessage'] ?? 'Unknown error';
                $responses[] = [
                    'recipientMsisdn' => $recipient['recipientMsisdn'],
                    'success' => false,
                    'details' => sprintf(
                        'Payout of %s %s to %s failed with Payout ID: %s. Reason: %s.',
                        $validatedAmount,
                        $recipient['currency'],
                        $recipient['recipientMsisdn'],
                        $recipient['payoutId'],
                        $failureReason
                    ),
                    'error' => $failureReason
                ];
                $log->error('Payout failed', [
                    'payoutId' => $recipient['payoutId'],
                    'error' => $failureReason
                ]);
            }

        } catch (Exception $e) {
            // Catch validation errors and display the message
            $responses[] = [
                'recipientMsisdn' => $recipientData['recipientMsisdn'],
                'success' => false,
                'details' => sprintf(
                    'Payout of %s %s to %s failed during processing. Reason: %s.',
                    $recipientData['amount'],
                    $recipientData['currency'],
                    $recipientData['recipientMsisdn'],
                    $e->getMessage()
                ),
                'error' => $e->getMessage()
            ];
            // Log the error if payoutId is set, else log with recipient info
            $log->error('Payout processing error', [
                'recipientMsisdn' => $recipientData['recipientMsisdn'],
                'error' => $e->getMessage()
            ]);
        }
    }

    // Summarize the outcome of all payout attempts
    $successfulCount = count(array_filter($responses, fn ($item) => $item['success']));
    $failedCount = count($responses) - $successfulCount;

    // Return the responses as JSON
    $response = [
        'success' => $failedCount === 0,
        'message' => sprintf(
            'Payout processing completed. %d successful and %d failed.',
            $successfulCount,
            $failedCount
        ),
        'responses' => $responses,
        'total_recipients' => count($recipientsData)
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} else {
    // Handle the error (if no data received)
    $response = [
        'success' => false,
        'message' => 'No data received.'
    ];
    echo json_encode($response, JSON_PRETTY_PRINT);
}