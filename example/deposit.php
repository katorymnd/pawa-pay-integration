<?php
// File: tests/deposit_switch_example.php

/**
 * Example runner, V1 and V2 in one place
 *
 * How it works
 * - ENVIRONMENT selects sandbox or production, defaults to sandbox.
 * - PAWAPAY_{ENV}_API_TOKEN supplies the token, for example PAWAPAY_SANDBOX_API_TOKEN.
 * - PAWAPAY_API_VERSION selects v1 or v2. Defaults to v1 to preserve old behavior.
 * - We validate inputs, then call initiateDepositAuto which chooses V1 or V2.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers;
use Katorymnd\PawaPayIntegration\Utils\Validator;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;

// Whoops error pages for development
$whoops = new Run();
$whoops->pushHandler(new PrettyPageHandler());
$whoops->register();

// Load .env at project root
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Environment and TLS
$environment = getenv('ENVIRONMENT') ?: 'sandbox';
$sslVerify   = $environment === 'production';

// API version switch, default v1
$apiVersion  = getenv('PAWAPAY_API_VERSION') ?: 'v1'; // switch v1 or v2

// Token key by environment
$apiTokenKey = 'PAWAPAY_' . strtoupper($environment) . '_API_TOKEN';
$apiToken    = $_ENV[$apiTokenKey] ?? null;
if (!$apiToken) {
    throw new Exception("API token not found for the selected environment");
}

// Logger
$log = new Logger('pawaPayLogger');
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_success.log', \Monolog\Level::Info));
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_failed.log', \Monolog\Level::Error));

// Client, version aware
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify, $apiVersion);

// Unique deposit id
$depositId = Helpers::generateUniqueId();

// Common demo inputs
$amount      = '1000';
$currency    = 'UGX';
$payerMsisdn = '256783456789';

// Description text
$customDescription = 'Order 4566';

// V1 specific fields
$correspondent = 'MTN_MOMO_UGA';

// V2 specific fields
$provider              = 'MTN_MOMO_UGA';
$clientReferenceId     = 'INV-123456';
$preAuthorisationCode  = null;

// Metadata examples
// V1 expects [{fieldName, fieldValue, isPII?}, ...]
$metadataV1 = [
    ['fieldName' => 'orderId',     'fieldValue' => 'ORD-123456789'],
    ['fieldName' => 'customerId',  'fieldValue' => 'customer@example.com', 'isPII' => true],
];

// V2 expects [{ orderId: "...", isPII?: bool }, ...]
$metadataV2 = [
    ['orderId' => 'ORD-123456789'],
    ['customerId' => 'customer@example.com', 'isPII' => true],
];

try {
    // Validate amount and narration
    $validatedAmount      = Validator::symfonyValidateAmount($amount);
    $validatedDescription = Validator::validateStatementDescription($customDescription);

    // Choose args per version
    if ($apiVersion === 'v2') {
        if (!empty($metadataV2)) {
            Validator::validateMetadataItemCount($metadataV2);
        }

        $args = [
            'depositId'            => $depositId,
            'amount'               => $validatedAmount,
            'currency'             => $currency,
            'payerMsisdn'          => $payerMsisdn,
            'provider'             => $provider,
            'customerMessage'      => $validatedDescription,
            'clientReferenceId'    => $clientReferenceId,
            'preAuthorisationCode' => $preAuthorisationCode,
            'metadata'             => $metadataV2,
        ];
    } else {
        if (!empty($metadataV1)) {
            Validator::validateMetadataItemCount($metadataV1);
        }

        $args = [
            'depositId'            => $depositId,
            'amount'               => $validatedAmount,
            'currency'             => $currency,
            'correspondent'        => $correspondent,
            'payerMsisdn'          => $payerMsisdn,
            'statementDescription' => $validatedDescription,
            'metadata'             => $metadataV1,
        ];
    }

    // Fire, version aware
    $response = $pawaPayClient->initiateDepositAuto($args);

    if ($response['status'] === 200) {
        echo "Deposit initiated successfully, version={$apiVersion}\n";
        print_r($response['response']);

        $log->info('Deposit initiated successfully', [
            'version'   => $apiVersion,
            'depositId' => $depositId,
            'response'  => $response['response'],
        ]);
    } else {
        echo "Error: Unable to initiate deposit, version={$apiVersion}\n";
        print_r($response);

        $log->error('Deposit initiation failed', [
            'version'   => $apiVersion,
            'depositId' => $depositId,
            'response'  => $response,
        ]);
    }
} catch (Exception $e) {
    echo "Validation or Request Error: " . $e->getMessage() . "\n";

    $log->error('Validation or request error', [
        'version'   => $apiVersion,
        'depositId' => $depositId,
        'error'     => $e->getMessage(),
    ]);
}