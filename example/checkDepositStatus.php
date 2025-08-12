<?php
// File: example/check_deposit_status.php
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;

// Dev error page (remove/guard in prod)
$whoops = new Run();
$whoops->pushHandler(new PrettyPageHandler());
$whoops->register();

// .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Env / SSL / token
$environment = getenv('ENVIRONMENT') ?: 'sandbox';
$sslVerify   = ($environment === 'production');
$apiTokenKey = 'PAWAPAY_' . strtoupper($environment) . '_API_TOKEN';
$apiToken    = $_ENV[$apiTokenKey] ?? null;
if (!$apiToken) {
    echo json_encode(['success' => false, 'errorMessage' => 'API token not found for the selected environment.']);
    exit;
}

// Choose API version (env default, overridable via GET/POST)
// Choose  version: 'v1' or 'v2' for both
// $apiVersion 
// $apiVersion 

$apiVersion = $_GET['apiVersion'] ?? $_POST['apiVersion'] ?? (getenv('PAWAPAY_API_VERSION') ?: 'v1'); // Choose  version: 'v1' or 'v2'
$apiVersion = in_array($apiVersion, ['v1', 'v2'], true) ? $apiVersion : 'v1'; // Choose  version: 'v1' or 'v2'

// Logging
$log = new Logger('pawaPayLogger');
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_success.log', \Monolog\Level::Info));
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_failed.log',  \Monolog\Level::Error));

// Api client (version-aware)
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify, $apiVersion);

// Deposit ID (allow override via GET/POST)
$depositId = $_GET['depositId'] ?? $_POST['depositId'] ?? '7c3c5b43-2514-4ea2-a446-fa2b0c3a40f9'; // <-- replace with a real UUID

try {
    // Version-aware status call (requires checkTransactionStatusAuto in ApiClient)
    $response = $pawaPayClient->checkTransactionStatusAuto($depositId, 'deposit');

    $http = (int)($response['status'] ?? 0);
    if ($http !== 200) {
        $log->error('Failed to retrieve deposit status', ['depositId' => $depositId, 'response' => $response, 'version' => $apiVersion]);
        echo json_encode([
            'success'      => false,
            'errorMessage' => 'Unable to retrieve deposit status.',
            'httpStatus'   => $http,
            'version'      => $apiVersion,
            'raw'          => $response['response'] ?? null
        ]);
        exit;
    }

    // Normalize v1/v2 payloads for a consistent output
    $normalized = [
        'success'     => true,
        'version'     => $apiVersion,
        'httpStatus'  => 200,
        'depositId'   => $depositId,
        'depositStatus' => null,
        'apiStatus'   => null,   // v2: FOUND/NOT_FOUND; v1: always OK
        'raw'         => $response['response'],
    ];

    if ($apiVersion === 'v2') {
        // v2 payload: { status: FOUND|NOT_FOUND, data: {...} }
        $apiStatus = $response['response']['status'] ?? null;         // FOUND / NOT_FOUND
        $normalized['apiStatus'] = $apiStatus;

        if ($apiStatus !== 'FOUND') {
            // Treat NOT_FOUND as still processing (race during eventual consistency)
            $normalized['depositStatus'] = 'PROCESSING';
            $log->info('Deposit not yet found (v2)', ['depositId' => $depositId, 'response' => $response['response']]);
        } else {
            $data = $response['response']['data'] ?? [];
            $normalized['depositStatus'] = $data['status'] ?? null;    // COMPLETED, FAILED, etc.
        }
    } else {
        // v1 payload: array with at most one item
        $item = $response['response'][0] ?? [];
        $normalized['apiStatus']     = 'OK';
        $normalized['depositStatus'] = $item['status'] ?? null;       // COMPLETED, FAILED, etc.
    }

    // Log & output
    $log->info('Deposit status retrieved', [
        'depositId'     => $depositId,
        'version'       => $apiVersion,
        'depositStatus' => $normalized['depositStatus'],
        'apiStatus'     => $normalized['apiStatus'],
    ]);

    echo json_encode($normalized, JSON_PRETTY_PRINT);
    exit;
} catch (\Throwable $e) {
    $log->error('Error occurred while checking deposit status', [
        'depositId' => $depositId,
        'version'   => $apiVersion,
        'error'     => $e->getMessage()
    ]);

    echo json_encode([
        'success'      => false,
        'errorMessage' => $e->getMessage(),
        'version'      => $apiVersion
    ]);
    exit;
}