<?php

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers;
use Katorymnd\PawaPayIntegration\Utils\Validator;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;

// Dev error page
$whoops = new Run();
$whoops->pushHandler(new PrettyPageHandler());
$whoops->register();

// .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Environment / token / SSL / API version
$environment = getenv('ENVIRONMENT') ?: 'sandbox';
$sslVerify   = ($environment === 'production');
// Choose API version: 'v1' or 'v2'
$apiVersion  = getenv('PAWAPAY_API_VERSION') ?: 'v1';   // Change to v1 or v2
$apiTokenKey = 'PAWAPAY_' . strtoupper($environment) . '_API_TOKEN';
$apiToken    = $_ENV[$apiTokenKey] ?? null;

if (!$apiToken) {
    echo json_encode(['success' => false, 'errorMessage' => 'API token not found for the selected environment.']);
    exit;
}

// Logging
$log = new Logger('pawaPayLogger');
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_success.log', \Monolog\Level::Info));
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_failed.log',  \Monolog\Level::Error));

// Client (version-aware)
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify, $apiVersion); 

// Inputs
$depositId            = Helpers::generateUniqueId();
$returnUrl            = isset($_POST['returnUrl']) ? trim($_POST['returnUrl']) : 'https://example.com/paymentProcessed';
$statementDescription = isset($_POST['statementDescription']) ? trim($_POST['statementDescription']) : 'ProjectPayment123';
$amount               = isset($_POST['amount']) ? trim($_POST['amount']) : '15000';
$currency             = isset($_POST['currency']) ? strtoupper(trim($_POST['currency'])) : 'UGX'; 
$msisdn               = isset($_POST['msisdn']) ? preg_replace('/\D/', '', trim($_POST['msisdn'])) : '256753456789';
$language             = isset($_POST['language']) ? strtoupper(trim($_POST['language'])) : 'EN';
$country              = isset($_POST['country']) ? strtoupper(trim($_POST['country'])) : 'UGA';
$reason               = isset($_POST['reason']) ? trim($_POST['reason']) : 'Project payment';
$metadata             = [];

// metadata can be JSON string or array
$metadataRaw = $_POST['metadata'] ?? '';

try {
    // Validate description (4–22)
    $statementDescription = Validator::validateStatementDescription($statementDescription);

    // Validate amount if provided
    if ($amount !== '') {
        $amount = Validator::symfonyValidateAmount($amount);
    }

    // Parse/validate metadata
    if ($metadataRaw !== '' && $metadataRaw !== null) {
        if (is_string($metadataRaw)) {
            $decoded = json_decode($metadataRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new InvalidArgumentException('Invalid metadata JSON.');
            }
            $metadata = $decoded;
        } elseif (is_array($metadataRaw)) {
            $metadata = $metadataRaw;
        } else {
            throw new InvalidArgumentException('Unsupported metadata format.');
        }
        if (!empty($metadata) && method_exists(Validator::class, 'validateMetadataItemCount')) {
            Validator::validateMetadataItemCount($metadata);
        }
    }

    // Build params once; client will adapt to v1/v2
    $params = [
        'depositId'            => $depositId,
        'returnUrl'            => $returnUrl,
        // V1 name; client maps to V2 'customerMessage' automatically
        'statementDescription' => $statementDescription,
        // V1 fields:
        'amount'               => $amount,
        'msisdn'               => $msisdn,
        // Common
        'language'             => $language,
        'country'              => $country,
        'reason'               => $reason,
        'metadata'             => $metadata,
    ];

    // If currency also provided, client will send amountDetails for V2 automatically
    if ($currency !== '') {
        $params['currency'] = $currency;
    }

    // Call auto (routes to v1 or v2)
    $resp = $pawaPayClient->createPaymentPageSessionAuto($params); // << UPDATED

    if (in_array($resp['status'], [200, 201], true)) {
        $redirectUrl = $resp['response']['redirectUrl'] ?? null;

        if ($redirectUrl) {
            $log->info('Payment Page session created', [
                'depositId'   => $depositId,
                'redirectUrl' => $redirectUrl,
                'response'    => $resp['response'],
            ]);

            echo json_encode([
                'success'     => true,
                'depositId'   => $depositId,
                'redirectUrl' => $redirectUrl,
                'version'     => $apiVersion, // helpful to surface
            ]);
            exit;
        }

        $log->error('No redirectUrl in successful response', ['depositId' => $depositId, 'response' => $resp['response']]);
        echo json_encode(['success' => false, 'errorMessage' => 'No redirectUrl returned by API.']);
        exit;
    }

    // Non-success HTTP status
    $log->error('Failed to create Payment Page session', ['depositId' => $depositId, 'response' => $resp]);
    echo json_encode([
        'success'      => false,
        'errorMessage' => 'Failed to create Payment Page session.',
        'status'       => $resp['status'] ?? null,
        'raw'          => $resp['response'] ?? null,
        'version'      => $apiVersion,
    ]);
    exit;
} catch (Throwable $e) {
    $log->error('Error creating Payment Page session', [
        'depositId' => $depositId,
        'error'     => $e->getMessage(),
        'trace'     => $e->getTraceAsString(),
    ]);

    echo json_encode(['success' => false, 'errorMessage' => $e->getMessage()]);
    exit;
}