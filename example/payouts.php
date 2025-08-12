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

// --- Country name -> ISO3 (all african countries) ---
$COUNTRY_TO_ISO3 = [
    'Algeria' => 'DZA',
    'Angola' => 'AGO',
    'Benin' => 'BEN',
    'Botswana' => 'BWA',
    'Burkina Faso' => 'BFA',
    'Burundi' => 'BDI',
    'Cabo Verde' => 'CPV',
    'Cameroon' => 'CMR',
    'Central African Republic' => 'CAF',
    'Chad' => 'TCD',
    'Comoros' => 'COM',
    'Congo' => 'COG',
    'Congo (DRC)' => 'COD',
    "Cote D'Ivoire" => 'CIV',
    'Djibouti' => 'DJI',
    'Egypt' => 'EGY',
    'Equatorial Guinea' => 'GNQ',
    'Eritrea' => 'ERI',
    'Eswatini' => 'SWZ',
    'Ethiopia' => 'ETH',
    'Gabon' => 'GAB',
    'Gambia' => 'GMB',
    'Ghana' => 'GHA',
    'Guinea' => 'GIN',
    'Guinea-Bissau' => 'GNB',
    'Kenya' => 'KEN',
    'Lesotho' => 'LSO',
    'Liberia' => 'LBR',
    'Libya' => 'LBY',
    'Madagascar' => 'MDG',
    'Malawi' => 'MWI',
    'Mali' => 'MLI',
    'Mauritania' => 'MRT',
    'Mauritius' => 'MUS',
    'Morocco' => 'MAR',
    'Mozambique' => 'MOZ',
    'Namibia' => 'NAM',
    'Niger' => 'NER',
    'Nigeria' => 'NGA',
    'Rwanda' => 'RWA',
    'Sao Tome and Principe' => 'STP',
    'Senegal' => 'SEN',
    'Seychelles' => 'SYC',
    'Sierra Leone' => 'SLE',
    'Somalia' => 'SOM',
    'South Africa' => 'ZAF',
    'South Sudan' => 'SSD',
    'Sudan' => 'SDN',
    'Tanzania' => 'TZA',
    'Togo' => 'TGO',
    'Tunisia' => 'TUN',
    'Uganda' => 'UGA',
    'Zambia' => 'ZMB',
    'Zimbabwe' => 'ZWE'
];
$countryToIso3 = static function (?string $name) use ($COUNTRY_TO_ISO3): ?string {
    if (!$name) return null;
    $name = trim($name);
    return $COUNTRY_TO_ISO3[$name] ?? strtoupper($name); // if already ISO3, keep it
};

// Set the environment and SSL verification based on the production status
$environment = getenv('ENVIRONMENT') ?: 'sandbox';
$sslVerify   = $environment === 'production';

// API version (DEFAULT v1 now). Allow top-level override in request body too.
$defaultApiVersion = strtolower(getenv('PAWAPAY_API_VERSION') ?: 'v1');// choose v1 or v2

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

// Create a new instance of the API client with SSL verification control + version
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify, $defaultApiVersion);

// Get the raw POST data (JSON) sent from the JavaScript fetch request
$data = json_decode(file_get_contents('php://input'), true);

if ($data) {
    // Optional per-request version override at the root: { "apiVersion": "v2", "recipients":[...] }
    $requestApiVersion = strtolower($data['apiVersion'] ?? $defaultApiVersion);

    // Access the form data
    $recipientsData = isset($data['recipients']) ? $data['recipients'] : [];
    $responses = [];

    foreach ($recipientsData as $index => $recipientData) {
        try {
            // Optional per-recipient override: each recipient can carry apiVersion too
            $effectiveVersion = strtolower($recipientData['apiVersion'] ?? $requestApiVersion);
            if (!in_array($effectiveVersion, ['v1', 'v2'], true)) {
                $effectiveVersion = 'v1';
            }

            // Common fields (presence validated below)
            $recipient = [
                'payoutId'        => Helpers::generateUniqueId(),
                'amount'          => $recipientData['amount'] ?? null,
                'currency'        => $recipientData['currency'] ?? null,
                'recipientMsisdn' => $recipientData['recipientMsisdn'] ?? null,
                // if you ever need country in v1 (must be ISO3)
                'countryIso3'     => isset($recipientData['country']) ? $countryToIso3($recipientData['country']) : null,
            ];

            // ---- Shape adaptation (surgical) ----
            if ($effectiveVersion === 'v2') {
                // Accept native v2 OR map v1 fields to v2
                $recipient['provider'] = $recipientData['provider']
                    ?? $recipientData['correspondent'] // v1 name → v2
                    ?? null;

                // If you ever want to synthesize provider from (mno + country), uncomment:
                // if (!$recipient['provider'] && !empty($recipientData['mno']) && !empty($recipient['countryIso3'])) {
                //     $recipient['provider'] = strtoupper($recipientData['mno']) . '_' . $recipient['countryIso3'];
                // }

                $recipient['customerMessage'] = $recipientData['customerMessage']
                    ?? $recipientData['statementDescription'] // v1 name → v2
                    ?? null;

                if (empty($recipient['provider'])) {
                    throw new Exception('Missing required field for v2 payout: provider');
                }
            } else {
                // v1: accept native v1 OR map v2 fields to v1
                $recipient['correspondent'] = $recipientData['correspondent']
                    ?? $recipientData['provider'] // v2 name → v1
                    ?? null;

                $recipient['statementDescription'] = $recipientData['statementDescription']
                    ?? $recipientData['customerMessage'] // v2 name → v1
                    ?? 'Payout to customer';

                if (empty($recipient['correspondent']) || empty($recipient['statementDescription'])) {
                    throw new Exception('Missing required fields for v1 payout: correspondent and/or statementDescription');
                }
            }

            // Validate common requireds
            if (empty($recipient['amount']) || empty($recipient['currency']) || empty($recipient['recipientMsisdn'])) {
                throw new Exception('Missing required fields: amount, currency or recipientMsisdn');
            }

            // Metadata (normalize v1 style → v2 objects if needed later)
            $metadata = isset($recipientData['metadata']) ? $recipientData['metadata'] : [];

            // Validate amount
            $validatedAmount = Validator::symfonyValidateAmount($recipient['amount']);

            // Validate narration if present (v1 required; v2 optional)
            $validatedDescription = null;
            if ($effectiveVersion === 'v1') {
                $validatedDescription = Validator::validateStatementDescription($recipient['statementDescription']);
            } elseif (!empty($recipient['customerMessage'])) {
                $validatedDescription = Validator::validateStatementDescription($recipient['customerMessage']);
            }

            // Build args for ApiClient->initiatePayoutAuto(...)
            $args = [
                'payoutId'        => $recipient['payoutId'],
                'amount'          => $validatedAmount,
                'currency'        => $recipient['currency'],
                'recipientMsisdn' => $recipient['recipientMsisdn'],
                'metadata'        => $metadata,
            ];

            if ($effectiveVersion === 'v2') {
                $args['provider'] = $recipient['provider'];
                if (!empty($validatedDescription)) {
                    $args['customerMessage'] = $validatedDescription;
                }

                // Optional: normalize v1-style metadata here to v2 objects
                if (!empty($args['metadata']) && is_array($args['metadata'])) {
                    $norm = [];
                    foreach ($args['metadata'] as $m) {
                        if (isset($m['fieldName'], $m['fieldValue'])) {
                            $obj = [(string)$m['fieldName'] => $m['fieldValue']];
                            if (array_key_exists('isPII', $m)) $obj['isPII'] = (bool)$m['isPII'];
                            $norm[] = $obj;
                        } else {
                            $norm[] = $m;
                        }
                    }
                    $args['metadata'] = array_values($norm);
                }
            } else {
                $args['correspondent']        = $recipient['correspondent'];
                $args['statementDescription'] = $validatedDescription; // required in v1
                // If your v1 ApiClient ever supports 'country', pass $recipient['countryIso3'] here.
            }

            // Initiate the payout (ApiClient auto-routes based on its $apiVersion)
            // To ensure the client routes as we intend per recipient, we can temporarily switch if needed:
            // (Only if your ApiClient exposes a setter; otherwise construct one client per version.)
            // For simplicity, assume the client's version is fine; it will still work if args match the route signature.
            $initiateResponse = $pawaPayClient->initiatePayoutAuto($args);

            // Short delay before status check
            sleep(2);

            // Check the payout status (auto-routes too)
            $statusResponse = $pawaPayClient->checkTransactionStatusAuto($recipient['payoutId'], 'payout');

            // ---- Normalize v1/v2/legacy status payloads (surgical) ----
            $body          = $statusResponse['response'] ?? null;
            $status        = null;        // final txn status (e.g. COMPLETED/FAILED/PENDING)
            $failureReason = null;
            $flat          = [];          // flattened node for UI (v2=data, v1=top-level, legacy=[0])

            if (is_array($body)) {
                if (isset($body['data']) && is_array($body['data'])) {
                    // v2 shape: { status: "FOUND", data: {...actual txn...} }
                    $dataNode     = $body['data'];
                    $status       = $dataNode['status'] ?? null; // <-- real txn status
                    $failureReason = $dataNode['failureReason']['failureMessage']
                        ?? ($body['failureReason']['failureMessage'] ?? null);
                    $flat         = $dataNode;
                } elseif (array_key_exists('status', $body)) {
                    // v1 (or direct object) shape: status at top-level
                    $status       = $body['status'];
                    $failureReason = $body['failureReason']['failureMessage'] ?? null;
                    $flat         = $body;
                } elseif (isset($body[0]) && is_array($body[0])) {
                    // legacy array-of-one
                    $status       = $body[0]['status'] ?? null;
                    $failureReason = $body[0]['failureReason']['failureMessage'] ?? null;
                    $flat         = $body[0];
                }
            }

            // Prepare the final response based on the normalized status
            if ($statusResponse['status'] === 200 && strtoupper((string)$status) === 'COMPLETED') {
                $responses[] = [
                    'recipientMsisdn' => $recipient['recipientMsisdn'],
                    'success'         => true,
                    'details'         => sprintf(
                        'Payout of %s %s to %s was completed successfully with Payout ID: %s. [version=%s]',
                        $validatedAmount,
                        $recipient['currency'],
                        $recipient['recipientMsisdn'],
                        $recipient['payoutId'],
                        $effectiveVersion
                    ),
                    'status'   => $status,
                    'payoutId' => $flat['payoutId'] ?? $recipient['payoutId'],
                    'amount'   => $flat['amount']   ?? $validatedAmount,
                    'currency' => $flat['currency'] ?? $recipient['currency'],
                    'response' => $body
                ];
                $log->info('Payout completed successfully', [
                    'version'  => $effectiveVersion,
                    'payoutId' => $recipient['payoutId'],
                    'response' => $body
                ]);
            } else {
                $failureReason = $failureReason ?: 'Unknown error';
                $responses[] = [
                    'recipientMsisdn' => $recipient['recipientMsisdn'],
                    'success'         => false,
                    'details'         => sprintf(
                        'Payout of %s %s to %s failed or not completed yet with Payout ID: %s. Reason: %s. [version=%s]',
                        $validatedAmount,
                        $recipient['currency'],
                        $recipient['recipientMsisdn'],
                        $recipient['payoutId'],
                        $failureReason,
                        $effectiveVersion
                    ),
                    'status'   => $status,
                    'payoutId' => $flat['payoutId'] ?? $recipient['payoutId'],
                    'amount'   => $flat['amount']   ?? $validatedAmount,
                    'currency' => $flat['currency'] ?? $recipient['currency'],
                    'error'    => $failureReason,
                    'response' => $body
                ];
                $log->error('Payout failed or not completed', [
                    'version'  => $effectiveVersion,
                    'payoutId' => $recipient['payoutId'],
                    'error'    => $failureReason,
                    'response' => $body
                ]);
            }
        } catch (Exception $e) {
            $responses[] = [
                'recipientMsisdn' => $recipientData['recipientMsisdn'] ?? null,
                'success' => false,
                'details' => sprintf(
                    'Payout of %s %s to %s failed during processing. Reason: %s. [version=%s]',
                    $recipientData['amount'] ?? 'N/A',
                    $recipientData['currency'] ?? 'N/A',
                    $recipientData['recipientMsisdn'] ?? 'N/A',
                    $e->getMessage(),
                    $recipientData['apiVersion'] ?? ($data['apiVersion'] ?? $defaultApiVersion)
                ),
                'error' => $e->getMessage()
            ];
            $log->error('Payout processing error', [
                'version'        => $recipientData['apiVersion'] ?? ($data['apiVersion'] ?? $defaultApiVersion),
                'recipientMsisdn' => $recipientData['recipientMsisdn'] ?? null,
                'error'          => $e->getMessage()
            ]);
        }
    }

    $successfulCount = count(array_filter($responses, fn($item) => $item['success']));
    $failedCount     = count($responses) - $successfulCount;

    echo json_encode([
        'success' => $failedCount === 0,
        'message' => sprintf(
            'Payout processing completed. %d successful and %d failed. [default=%s]',
            $successfulCount,
            $failedCount,
            $defaultApiVersion
        ),
        'responses' => $responses,
        'total_recipients' => count($recipientsData)
    ], JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'No data received.'
    ], JSON_PRETTY_PRINT);
}