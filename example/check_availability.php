<?php

// Include the Composer autoload for dependencies
require_once __DIR__ . '/../vendor/autoload.php';

use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
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

// Initialize Monolog for logging
$log = new Logger('pawaPayLogger');
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_success.log', \Monolog\Level::Info));
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/payment_failed.log', \Monolog\Level::Error));

// Create an API client instance
$pawaPayClient = new ApiClient($apiToken, $environment, $sslVerify);

try {
    // Check MNO availability
    $response = $pawaPayClient->checkMNOAvailability();

    // Handle the response based on status code
    if ($response['status'] === 200) {
        // Save the response to a JSON file
        $jsonData = $response['response'];
        $jsonFilePath = __DIR__ . '/../data/mno_availability.json';

        // Ensure that the existing file is overwritten
        // The following line will overwrite the file if it exists
        file_put_contents($jsonFilePath, json_encode($jsonData, JSON_PRETTY_PRINT));

        // Generate HTML output
        generateHtmlOutput($jsonData);

        // Log the success
        $log->info('MNO availability retrieved and displayed successfully', [
            'response' => $response['response']
        ]);
    } else {
        echo "Error: Unable to retrieve MNO availability.\n";
        print_r($response);

        // Log the failure
        $log->error('Failed to retrieve MNO availability', [
            'response' => $response
        ]);
    }
} catch (Exception $e) {
    // Display and log any errors
    echo "Error: " . $e->getMessage() . "\n";
    $log->error('Error occurred', [
        'error' => $e->getMessage()
    ]);
}

/**
 * Function to generate HTML output
 */
function generateHtmlOutput($data)
{
    // Use the ISO3166 class to handle country codes
    $iso3166 = new ISO3166();

    ?>
<!DOCTYPE html>
<html>

<head>
    <title>MNO Availability</title>
    <style>
    body {
        font-family: Arial, sans-serif;
        margin: 20px;
        background-color: #f2f2f2;
    }

    h1 {
        text-align: center;
    }

    .country-section {
        background-color: #fff;
        padding: 20px;
        margin-bottom: 20px;
        border-radius: 8px;
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
    <h1>Available Mobile Network Operators</h1>
    <?php foreach ($data as $countryData): ?>
    <div class="country-section">
        <?php
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
        ?>
        <div class="country-name">Country: <?php echo htmlspecialchars($countryName); ?>
            (<?php echo htmlspecialchars($countryCodeAlpha3); ?>)</div>
        <?php foreach ($countryData['correspondents'] as $correspondentData): ?>
        <div class="correspondent">
            <div class="correspondent-name">Correspondent:
                <?php echo htmlspecialchars($correspondentData['correspondent']); ?></div>
            <ul class="operation-list">
                <?php foreach ($correspondentData['operationTypes'] as $operationTypeData): ?>
                <li class="operation-item">
                    Operation: <?php echo htmlspecialchars($operationTypeData['operationType']); ?> -
                    Status: <?php echo htmlspecialchars($operationTypeData['status']); ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="separator"></div>
    <?php endforeach; ?>
</body>

</html>
<?php
}