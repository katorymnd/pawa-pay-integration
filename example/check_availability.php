<?php
/**
 * Standalone MNO Availability Viewer, V1 or V2 selectable
 *
 * What this script does
 * - Loads env, logging, and error handling.
 * - Switches between pawaPay V1 and V2 availability endpoints without breaking your flow.
 * - Fetches availability with ApiClient::checkMNOAvailabilityAuto() based on PAWAPAY_API_VERSION.
 * - Saves raw JSON to /data/mno_availability_{version}.json.
 * - Renders a clean HTML view that understands both V1 (correspondents) and V2 (providers).
 *
 * How to switch versions
 * - ENVIRONMENT = sandbox or production, defaults to sandbox.
 * - PAWAPAY_{ENV}_API_TOKEN supplies your token.
 * - PAWAPAY_API_VERSION = v1 [default] or v2.
 *
 * Display logic
 * - V1: countries[].correspondents[].operationTypes[] where each has {operationType, status}.
 * - V2: countries[].providers[].operationTypes[] usually same shape, but we also guard for map-like shapes.
 * - Country names are beautified via ISO3166 + Symfony Intl.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;
use Symfony\Component\Intl\Countries;
use League\ISO3166\ISO3166;

/**
 * Bootstrap, env, logging, and client
 */
$whoops = new Run();
$whoops->pushHandler(new PrettyPageHandler());
$whoops->register();

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$environment = getenv('ENVIRONMENT') ?: 'sandbox';
$sslVerify   = $environment === 'production';
$apiVersion  = getenv('PAWAPAY_API_VERSION') ?: 'v1'; 

$apiTokenKey = 'PAWAPAY_' . strtoupper($environment) . '_API_TOKEN';
$apiToken    = $_ENV[$apiTokenKey] ?? null;
if (!$apiToken) {
    throw new Exception("API token not found for the selected environment");
}

// Logging
$log = new Logger('pawaPayLogger');
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_success.log', \Monolog\Level::Info));
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_failed.log', \Monolog\Level::Error));

// Version-aware client
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify, $apiVersion);

try {
    // Fetch availability, routed by $apiVersion inside the client
    if (method_exists($pawaPayClient, 'checkMNOAvailabilityAuto')) {
        $response = $pawaPayClient->checkMNOAvailabilityAuto(); // if you added filters, pass them here
    } else {
        // Backward fallback to V1-only method, keeps old projects happy
        $response = ($apiVersion === 'v2')
            ? ['status' => 501, 'response' => ['error' => 'checkMNOAvailabilityAuto not available in ApiClient']]
            : $pawaPayClient->checkMNOAvailability();
    }

    if ($response['status'] === 200) {
        $jsonData = $response['response'];

        // Save to versioned file for easy diffing
        $jsonFilePath = __DIR__ . '/../data/mno_availability_' . $apiVersion . '.json';
        ensureDataDir(dirname($jsonFilePath));
        file_put_contents($jsonFilePath, json_encode($jsonData, JSON_PRETTY_PRINT));

        // Render unified HTML
        generateHtmlOutput($apiVersion, $jsonData);

        $log->info('MNO availability retrieved and displayed successfully', [
            'version'  => $apiVersion,
            'response' => $jsonData,
        ]);
    } else {
        echo "Error: Unable to retrieve MNO availability. [version={$apiVersion}]\n";
        print_r($response);

        $log->error('Failed to retrieve MNO availability', [
            'version'  => $apiVersion,
            'response' => $response
        ]);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    $log->error('Error occurred', ['error' => $e->getMessage()]);
}

/**
 * Ensure data directory exists
 *
 * @param string $dir
 * @return void
 */
function ensureDataDir(string $dir): void
{
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

/**
 * Generate HTML for both V1 and V2 shapes
 *
 * V1 example:
 * [
 *   {"country":"ZMB","correspondents":[
 *       {"correspondent":"MTN_MOMO_ZMB","operationTypes":[{"operationType":"DEPOSIT","status":"OPERATIONAL"}, ...]}
 *   ]}
 * ]
 *
 * V2 example:
 * [
 *   {"country":"ZMB","providers":[
 *       {"provider":"MTN_MOMO_ZMB","operationTypes":[{"operationType":"DEPOSIT","status":"OPERATIONAL"}, ...]}
 *   ]}
 * ]
 *
 * Some V2 responses might return an object map for operationTypes. We guard for that shape as well.
 *
 * @param string $apiVersion
 * @param array $data
 * @return void
 */
function generateHtmlOutput(string $apiVersion, array $data): void
{
    $iso3166 = new ISO3166();
    ?>
<!DOCTYPE html>
<html>

<head>
    <title>MNO Availability (<?php echo htmlspecialchars(strtoupper($apiVersion)); ?>)</title>
    <style>
    body {
        font-family: Arial, sans-serif;
        margin: 20px;
        background-color: #f7f7f9;
        opacity: 0;
        transition: opacity 0.4s ease-in-out;
    }

    h1 {
        text-align: center;
    }

    .meta {
        text-align: center;
        margin-bottom: 16px;
        color: #333;
    }

    .pill {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        background: #eceff4;
        margin: 0 6px;
        font-size: 12px;
    }

    .country-section {
        background-color: #fff;
        padding: 20px;
        margin-bottom: 20px;
        border-radius: 8px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
    }

    .country-name {
        font-size: 20px;
        margin-bottom: 10px;
        color: #333;
    }

    .item {
        margin-left: 10px;
        margin-bottom: 10px;
    }

    .name {
        font-size: 16px;
        color: #007BFF;
    }

    .operation-list {
        margin-left: 24px;
    }

    .operation-item {
        font-size: 14px;
        color: #555;
        margin: 3px 0;
    }

    .separator {
        height: 1px;
        background-color: #e5e9f0;
        margin: 20px 0;
    }
    </style>
</head>

<body>
    <h1>MNO Availability</h1>
    <div class="meta">
        <span class="pill">Environment: <?php echo htmlspecialchars(getenv('ENVIRONMENT') ?: 'sandbox'); ?></span>
        <span class="pill">API version: <?php echo htmlspecialchars($apiVersion); ?></span>
    </div>

    <?php foreach ($data as $countryData): ?>
    <?php
        $countryCodeAlpha3 = $countryData['country'] ?? 'N/A';
        try {
            $countryInfo = $iso3166->alpha3($countryCodeAlpha3);
            $countryCodeAlpha2 = $countryInfo['alpha2'];
            $countryName = Countries::getName($countryCodeAlpha2, 'en');
        } catch (\Exception $e) {
            $countryName = $countryCodeAlpha3;
        }

        // V1 uses 'correspondents', V2 uses 'providers'
        $list = $countryData['correspondents'] ?? $countryData['providers'] ?? [];
        ?>
    <div class="country-section">
        <div class="country-name">Country: <?php echo htmlspecialchars($countryName); ?>
            (<?php echo htmlspecialchars($countryCodeAlpha3); ?>)</div>

        <?php foreach ($list as $entry): ?>
        <?php
                // Identify code and friendly name if any (V1 has 'correspondent', V2 has 'provider' and may have 'displayName')
                $code = $entry['correspondent'] ?? $entry['provider'] ?? 'N/A';
                $display = $entry['displayName'] ?? $code;

                // Normalize operationTypes
                $ops = [];

                // Case A: array of {operationType, status}
                if (!empty($entry['operationTypes']) && is_array($entry['operationTypes']) && array_values($entry['operationTypes']) === $entry['operationTypes']) {
                    foreach ($entry['operationTypes'] as $opRow) {
                        if (isset($opRow['operationType'])) {
                            $ops[] = [
                                'operationType' => $opRow['operationType'],
                                'status'        => $opRow['status'] ?? 'UNKNOWN',
                            ];
                        }
                    }
                }
                // Case B: map-like object { "DEPOSIT":"OPERATIONAL", ... } or { "DEPOSIT": { "status":"OPERATIONAL", ... } }
                elseif (!empty($entry['operationTypes']) && is_array($entry['operationTypes'])) {
                    foreach ($entry['operationTypes'] as $k => $v) {
                        if (is_string($k)) {
                            if (is_string($v)) {
                                $ops[] = ['operationType' => $k, 'status' => $v];
                            } elseif (is_array($v)) {
                                $ops[] = ['operationType' => $k, 'status' => $v['status'] ?? 'UNKNOWN'];
                            }
                        }
                    }
                }
                ?>
        <div class="item">
            <div class="name"><?php echo htmlspecialchars($display); ?> <span
                    class="pill"><?php echo htmlspecialchars($code); ?></span></div>
            <?php if (!empty($ops)): ?>
            <ul class="operation-list">
                <?php foreach ($ops as $op): ?>
                <li class="operation-item">
                    Operation: <?php echo htmlspecialchars($op['operationType']); ?>,
                    Status: <?php echo htmlspecialchars($op['status']); ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <div class="operation-item">No operation types listed.</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="separator"></div>
    <?php endforeach; ?>

    <script>
    window.addEventListener('load', function() {
        document.body.style.opacity = '1';
    });
    </script>
</body>

</html>
<?php
}