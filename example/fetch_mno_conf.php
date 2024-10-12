<?php

// Include the Composer autoload for dependencies
require_once __DIR__ . '/../vendor/autoload.php';

use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Dotenv\Dotenv;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;
// Added these lines to use the Symfony Intl component and ISO3166
use Symfony\Component\Intl\Countries;
use League\ISO3166\ISO3166;

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


// Create an API client instance
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify);

try {
    // Check MNO availability
    $mnoResponse = $pawaPayClient->checkMNOAvailability();

    // Handle the MNO response based on status code
    if ($mnoResponse['status'] === 200) {
        // Save the MNO response to a JSON file
        $mnoJsonData = $mnoResponse['response'];
        $mnoJsonFilePath = __DIR__ . '/../data/mno_availability.json';

        // Ensure that the data directory exists
        if (!file_exists(__DIR__ . '/../data')) {
            mkdir(__DIR__ . '/../data', 0755, true);
        }

        file_put_contents($mnoJsonFilePath, json_encode($mnoJsonData, JSON_PRETTY_PRINT));

        // Output success message
        echo "MNO availability retrieved and saved successfully.\n";
    } else {
        echo "Error: Unable to retrieve MNO availability.\n";
        print_r($mnoResponse);
    }

    // Check Active Configuration
    $activeConfResponse = $pawaPayClient->checkActiveConf();

    // Handle the Active Configuration response based on status code
    if ($activeConfResponse['status'] === 200) {
        // Save the Active Configuration response to a JSON file
        $activeConfJsonData = $activeConfResponse['response'];
        $activeConfJsonFilePath = __DIR__ . '/../data/active_conf.json';

        file_put_contents($activeConfJsonFilePath, json_encode($activeConfJsonData, JSON_PRETTY_PRINT));

        // Output success message
        echo "Active Configuration retrieved and saved successfully.\n";
    } else {
        echo "Error: Unable to retrieve Active Configuration.\n";
        print_r($activeConfResponse);
    }

    // Generate HTML output combining both MNO and Active Configuration data
    generateHtmlOutput(
        $mnoResponse['status'] === 200 ? $mnoResponse['response'] : null,
        $activeConfResponse['status'] === 200 ? $activeConfResponse['response'] : null
    );

} catch (Exception $e) {
    // Display any errors
    echo "Error: " . $e->getMessage() . "\n";
}

/**
 * Function to generate HTML output with merged data
 */
function generateHtmlOutput($mnoData, $activeConfData)
{
    // Use the ISO3166 class to handle country codes
    $iso3166 = new ISO3166();

    // Build an associative array for active configuration data lookup
    $activeConfLookup = [];
    $merchantId = '';
    $merchantName = '';
    if ($activeConfData) {
        // Extract merchantId and merchantName
        $merchantId = $activeConfData['merchantId'] ?? '';
        $merchantName = $activeConfData['merchantName'] ?? '';

        if (isset($activeConfData['countries'])) {
            foreach ($activeConfData['countries'] as $countryData) {
                $countryCode = $countryData['country'];
                if (!isset($activeConfLookup[$countryCode])) {
                    $activeConfLookup[$countryCode] = [];
                }
                foreach ($countryData['correspondents'] as $correspondentData) {
                    $correspondentName = $correspondentData['correspondent'];
                    $activeConfLookup[$countryCode][$correspondentName] = $correspondentData;
                }
            }
        }
    }

    ?>
<!DOCTYPE html>
<html>

<head>
    <title>MNO Availability and Active Configuration</title>
    <style>
    body {
        font-family: Arial, sans-serif;
        margin: 20px;
        background-color: #f2f2f2;
        opacity: 0;
        /* Initially hide the body */
        transition: opacity 0.5s ease-in-out;
        /* Smooth transition when showing */
    }

    h1 {
        text-align: center;
    }

    .merchant-info {
        text-align: center;
        margin-bottom: 20px;
    }

    .merchant-info span {
        font-weight: bold;
    }

    .section {
        background-color: #fff;
        padding: 20px;
        margin-bottom: 20px;
        border-radius: 8px;
    }

    .section h2 {
        color: #333;
        border-bottom: 2px solid #007BFF;
        padding-bottom: 10px;
    }

    .country-section {
        margin-top: 20px;
    }

    .country-name {
        font-size: 24px;
        margin-bottom: 10px;
        color: #333;
    }

    .correspondent {
        margin-left: 20px;
        margin-bottom: 10px;
    }

    .correspondent-name {
        font-size: 20px;
        color: #007BFF;
    }

    .owner-name,
    .currency {
        margin-left: 20px;
        font-size: 16px;
        color: #555;
    }

    .operation-list {
        margin-left: 40px;
    }

    .operation-item {
        font-size: 16px;
        color: #555;
    }

    .separator {
        height: 1px;
        background-color: #ccc;
        margin: 20px 0;
    }
    </style>
</head>

<body>
    <h1>MNO Availability and Active Configuration</h1>
    <?php if ($merchantId || $merchantName): ?>
    <div class="merchant-info">
        <?php if ($merchantId): ?>
        <div>Merchant ID: <span><?php echo htmlspecialchars($merchantId); ?></span></div>
        <?php endif; ?>
        <?php if ($merchantName): ?>
        <div>Merchant Name: <span><?php echo htmlspecialchars($merchantName); ?></span></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($mnoData): ?>
    <div class="section">
        <h2>Available Mobile Network Operators</h2>
        <?php
        foreach ($mnoData as $countryData):
            $countryCodeAlpha3 = $countryData['country'];

            // Convert alpha-3 country code to alpha-2 code
            try {
                $countryInfo = $iso3166->alpha3($countryCodeAlpha3);
                $countryCodeAlpha2 = $countryInfo['alpha2'];
                $countryName = Countries::getName($countryCodeAlpha2, 'en');
            } catch (\Exception $e) {
                // If conversion fails, use the alpha-3 code as fallback
                $countryCodeAlpha2 = $countryCodeAlpha3;
                $countryName = $countryCodeAlpha3;
            }

            // Check if there are any valid correspondents to display
            $validCorrespondents = [];
            foreach ($countryData['correspondents'] as $correspondentData) {
                $correspondentName = $correspondentData['correspondent'];

                // Get active configuration data for this correspondent
                $activeCorrespondentData = $activeConfLookup[$countryCodeAlpha3][$correspondentName] ?? null;

                // Skip correspondents without ownerName
                if ($activeCorrespondentData && isset($activeCorrespondentData['ownerName'])) {
                    $validCorrespondents[] = [
                        'mnoData' => $correspondentData,
                        'activeData' => $activeCorrespondentData
                    ];
                }
            }

            // Skip the country if no valid correspondents
            if (empty($validCorrespondents)) {
                continue;
            }
            ?>
        <div class="country-section">
            <div class="country-name">Country: <?php echo htmlspecialchars($countryName); ?>
                (<?php echo htmlspecialchars($countryCodeAlpha3); ?>)</div>
            <?php foreach ($validCorrespondents as $correspondentPair): ?>
            <?php
                    $correspondentData = $correspondentPair['mnoData'];
                $activeCorrespondentData = $correspondentPair['activeData'];
                $correspondentName = $correspondentData['correspondent'];

                // Get ownerName, currency
                $ownerName = $activeCorrespondentData['ownerName'] ?? 'N/A';
                $currency = $activeCorrespondentData['currency'] ?? 'N/A';
                ?>
            <div class="correspondent">
                <div class="correspondent-name">Correspondent:
                    <?php echo htmlspecialchars($correspondentName); ?></div>
                <div class="owner-name">Owner Name: <?php echo htmlspecialchars($ownerName); ?></div>
                <div class="currency">Currency: <?php echo htmlspecialchars($currency); ?></div>
                <ul class="operation-list">
                    <?php foreach ($correspondentData['operationTypes'] as $operationTypeData): ?>
                    <?php
                            $operationType = $operationTypeData['operationType'];
                        $status = $operationTypeData['status'];

                        // Map PAYOUT to REFUND if needed
                        if ($operationType === 'PAYOUT') {
                            $operationType = 'REFUND';
                        }

                        // Find matching operationType data in active configuration
                        $activeOperationTypeData = null;
                        if ($activeCorrespondentData && isset($activeCorrespondentData['operationTypes'])) {
                            foreach ($activeCorrespondentData['operationTypes'] as $opTypeData) {
                                if ($opTypeData['operationType'] === $operationType) {
                                    $activeOperationTypeData = $opTypeData;
                                    break;
                                }
                            }
                        }

                        // Skip operation if not in active configuration
                        if (!$activeOperationTypeData) {
                            continue;
                        }

                        // Get minTransactionLimit and maxTransactionLimit
                        $minTransactionLimit = $activeOperationTypeData['minTransactionLimit'] ?? 'N/A';
                        $maxTransactionLimit = $activeOperationTypeData['maxTransactionLimit'] ?? 'N/A';
                        ?>
                    <li class="operation-item">
                        Operation: <?php echo htmlspecialchars($operationType); ?> -
                        Status: <?php echo htmlspecialchars($status); ?> -
                        Min Limit: <?php echo htmlspecialchars($minTransactionLimit); ?> -
                        Max Limit: <?php echo htmlspecialchars($maxTransactionLimit); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="separator"></div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="section">
        <h2>Mobile Network Operators Availability</h2>
        <p>No data available.</p>
    </div>
    <?php endif; ?>

    <!-- Add this script just before the closing body tag -->
    <script>
    window.addEventListener('load', function() {
        document.body.style.opacity = '1';
    });
    </script>
</body>

</html>
<?php
}
?>