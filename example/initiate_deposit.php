<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers;
use Katorymnd\PawaPayIntegration\Utils\Validator;
use Katorymnd\PawaPayIntegration\Utils\FailureCodeHelper;
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
$apiVersion  = getenv('PAWAPAY_API_VERSION') ?: 'v1';              // <— choose v1 or v2 globally
$apiVersion  = $_POST['apiVersion'] ?? $apiVersion;                // <— allow override per request -if provided from form data
$apiVersion  = in_array($apiVersion, ['v1', 'v2'], true) ? $apiVersion : 'v1';

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

// Client
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify, $apiVersion);

// Inputs from UI
$amount        = isset($_POST['amount']) ? trim($_POST['amount']) : '';
$mno           = isset($_POST['mno']) ? trim($_POST['mno']) : '';                 // v1: correspondent, v2: provider
$payerMsisdn   = isset($_POST['payerMsisdn']) ? preg_replace('/\D/', '', trim($_POST['payerMsisdn'])) : '';
$description   = isset($_POST['description']) ? trim($_POST['description']) : '';
$currency      = isset($_POST['currency']) ? strtoupper(trim($_POST['currency'])) : '';

// basic validation
if ($amount === '' || $mno === '' || $payerMsisdn === '' || $description === '' || $currency === '') {
    echo json_encode(['success' => false, 'errorMessage' => 'Missing required fields.']);
    exit;
}

$depositId = Helpers::generateUniqueId();
$metadata  = []; // fill if needed

try {
    // validate fields
    $validatedAmount      = Validator::symfonyValidateAmount($amount);
    $validatedDescription = Validator::validateStatementDescription($description);
    if (!empty($metadata) && method_exists(Validator::class, 'validateMetadataItemCount')) {
        Validator::validateMetadataItemCount($metadata);
    }

    // ---- Initiate (version-aware) -----------------------------------
    if ($apiVersion === 'v2') {
        // V2: provider + MMO structure, description → customerMessage
        $resp = $pawaPayClient->initiateDepositV2(
            $depositId,
            $validatedAmount,
            $currency,
            $payerMsisdn,
            $mno,                        // provider
            $validatedDescription,       // customerMessage
            null,                        // clientReferenceId (optional)
            null,                        // preAuthorisationCode (optional)
            $metadata
        );
    } else {
        // V1: correspondent + MSISDN structure, description → statementDescription
        $resp = $pawaPayClient->initiateDeposit(
            $depositId,
            $validatedAmount,
            $currency,
            $mno,                        // correspondent
            $payerMsisdn,                // payer.address.value
            $validatedDescription,
            $metadata
        );
    }

    if (($resp['status'] ?? 0) !== 200) {
        // Initiation rejected at gateway — try to surface a friendly message
        $msg = 'Payment initiation failed.';
        if (!empty($resp['response']['rejectionReason']['rejectionMessage'])) {
            $msg = 'Payment initiation failed: ' . $resp['response']['rejectionReason']['rejectionMessage'];
        } elseif (!empty($resp['response']['failureReason']['failureCode'])) {
            $msg = 'Payment initiation failed: ' . FailureCodeHelper::getFailureMessage($resp['response']['failureReason']['failureCode']);
        }
        $log->error('Deposit initiation failed', ['depositId' => $depositId, 'response' => $resp]);
        echo json_encode(['success' => false, 'errorMessage' => $msg, 'version' => $apiVersion]);
        exit;
    }

    // ---- Check status (version-aware) --------------------------------
    $statusResponse = $pawaPayClient->checkTransactionStatusAuto($depositId, 'deposit');

    if (($statusResponse['status'] ?? 0) !== 200) {
        $log->error('Failed to retrieve deposit status', ['depositId' => $depositId, 'response' => $statusResponse]);
        echo json_encode(['success' => false, 'errorMessage' => 'Unable to retrieve deposit status.', 'version' => $apiVersion]);
        exit;
    }

    // Normalize v1/v2 payloads
    if ($apiVersion === 'v2') {
        // v2: { status: FOUND|NOT_FOUND, data: {...} }
        if (($statusResponse['response']['status'] ?? '') !== 'FOUND') {
            // not found yet ⇒ treat as processing/pending
            $log->info('Deposit not yet found (v2)', ['depositId' => $depositId, 'response' => $statusResponse['response']]);
            echo json_encode([
                'success' => false,
                'errorMessage' => 'Payment is processing. Please wait and check your account.',
                'version' => $apiVersion
            ]);
            exit;
        }
        $depositInfo   = $statusResponse['response']['data'];
        $depositStatus = $depositInfo['status'] ?? 'PROCESSING';
    } else {
        // v1: array of deposits with at most one entry
        $depositInfo   = $statusResponse['response'][0] ?? [];
        $depositStatus = $depositInfo['status'] ?? 'PROCESSING';
    }

    // Final handling
    if ($depositStatus === 'COMPLETED') {
        $log->info('Deposit completed successfully', ['depositId' => $depositId, 'response' => $depositInfo]);
        echo json_encode([
            'success'        => true,
            'transactionId'  => $depositId,
            'message'        => 'Payment processed successfully.',
            'version'        => $apiVersion
        ]);
        exit;
    }

    if ($depositStatus === 'FAILED') {
        // surface failure
        $failureCode    = $depositInfo['failureReason']['failureCode'] ?? 'OTHER_ERROR';
        $failureMessage = FailureCodeHelper::getFailureMessage($failureCode);
        $log->error('Deposit failed', [
            'depositId'      => $depositId,
            'failureCode'    => $failureCode,
            'failureMessage' => $failureMessage,
            'response'       => $depositInfo
        ]);
        echo json_encode(['success' => false, 'errorMessage' => 'Payment failed: ' . $failureMessage, 'version' => $apiVersion]);
        exit;
    }

    // Any other intermediate state ⇒ processing
    $log->info('Deposit is in state: ' . $depositStatus, ['depositId' => $depositId, 'response' => $depositInfo]);
    echo json_encode([
        'success'      => false,
        'errorMessage' => 'Payment is processing. Please wait and check your account.',
        'status'       => $depositStatus,
        'version'      => $apiVersion
    ]);
    exit;
} catch (\Throwable $e) {
    $log->error('Validation/processing error', ['depositId' => $depositId, 'error' => $e->getMessage()]);
    echo json_encode(['success' => false, 'errorMessage' => 'Validation Error: ' . $e->getMessage(), 'version' => $apiVersion]);
    exit;
}