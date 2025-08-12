<?php
/**
 * pawaPay Payouts curl runner, V1 and V2 selectable
 *
 * What this script does
 *  1) Generates a UUIDv4 payoutId
 *  2) Lets you choose environment (sandbox or production)
 *  3) Lets you choose API version:
 *       - V1 -> POST /payouts
 *       - V2 -> POST /v2/payouts
 *  4) Sends the request with cURL and prints the response
 *
 * Why two versions
 *  - V1 uses correspondent + MSISDN structure and includes customerTimestamp, statementDescription.
 *  - V2 uses provider + MMO accountDetails and customerMessage, with simpler body.
 *
 * Practical notes
 *  - Flip $API_VERSION between 'v1' and 'v2' to compare behavior, nothing else changes.
 *  - Set $OUTPUT_RAW_JSON = true to print the exact JSON from pawaPay, useful for debugging.
 *  - In production, set CURLOPT_SSL_VERIFYPEER to true.
 */

// -------------------- Helper: Generate UUID v4 --------------------
/**
 * Generate a UUID v4 string suitable for payoutId
 */
function generateUuidV4(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

// -------------------- Output Mode --------------------
// true  = echo EXACT raw JSON as returned by API (no extra text)
// false = echo human-readable info (status + parsed fields)
$OUTPUT_RAW_JSON = true;

// -------------------- Environment and Version --------------------
// Choose: 'sandbox' or 'production'
$environment = 'sandbox';

// Choose API version: 'v1' or 'v2' (default 'v1' to preserve classic behavior)
$API_VERSION = 'v1';

// -------------------- Configuration --------------------
$config = [
    'sandbox' => [
        'api_url'   => 'https://api.sandbox.pawapay.io', // base host, we append the path per version
        'api_token' => '<your_sandbox_api_token_here>'
    ],
    'production' => [
        'api_url'   => 'https://api.pawapay.io',
        'api_token' => '<your_production_api_token_here>'
    ]
];

// Validate environment
if (!isset($config[$environment])) {
    die("Invalid environment. Use 'sandbox' or 'production'.");
}

$baseHost = rtrim($config[$environment]['api_url'], '/');
$apiToken = $config[$environment]['api_token'];

// -------------------- Endpoint selection --------------------
$endpoint = ($API_VERSION === 'v2') ? '/v2/payouts' : '/payouts';
$apiUrl   = $baseHost . $endpoint;

// -------------------- Common Inputs, then map per version --------------------
$payoutId          = generateUuidV4();
$commonAmount      = '1000';
$commonCurrency    = 'UGX';
$recipientMsisdn   = '256783456789'; // international format without +
$countryAlpha3     = 'UGA';          // V1 needs country, V2 does not
$v1Correspondent   = 'MTN_MOMO_UGA'; // V1 correspondent code
$v2Provider        = 'MTN_MOMO_UGA'; // V2 provider code
$customerNarration = 'Project pay'; // V1 statementDescription, V2 customerMessage

// V1 style metadata (fieldName, fieldValue, isPII?)
$metadataV1 = [
    [
        'fieldName'  => 'orderId',
        'fieldValue' => 'ORD-123456789'
    ],
    [
        'fieldName'  => 'customerId',
        'fieldValue' => 'customer@email.com',
        'isPII'      => true
    ]
];

// Map V1 metadata to V2 shape [{orderId: "...", isPII?: bool}, ...]
function mapMetadataV1ToV2(array $metaV1): array
{
    $out = [];
    foreach ($metaV1 as $row) {
        if (isset($row['fieldName'], $row['fieldValue'])) {
            $key = $row['fieldName'];
            $val = $row['fieldValue'];
            $item = [$key => $val];
            if (array_key_exists('isPII', $row)) {
                $item['isPII'] = (bool)$row['isPII'];
            }
            $out[] = $item;
        }
    }
    return $out;
}

// -------------------- Build payload per version --------------------
if ($API_VERSION === 'v2') {
    // V2 payout payload
    $payload = [
        'payoutId'  => $payoutId,
        'recipient' => [
            'type'           => 'MMO',
            'accountDetails' => [
                'phoneNumber' => $recipientMsisdn,
                'provider'    => $v2Provider
            ]
        ],
        'customerMessage' => $customerNarration,
        'amount'          => $commonAmount,
        'currency'        => $commonCurrency,
        'metadata'        => mapMetadataV1ToV2($metadataV1)
    ];
} else {
    // V1 payout payload
    $payload = [
        'payoutId'  => $payoutId,
        'amount'    => $commonAmount,
        'currency'  => $commonCurrency,
        'country'   => $countryAlpha3,
        'correspondent' => $v1Correspondent,
        'recipient' => [
            'type'    => 'MSISDN',
            'address' => ['value' => $recipientMsisdn]
        ],
        'customerTimestamp'    => (new DateTime())->format(DateTime::ATOM),
        'statementDescription' => $customerNarration,
        'metadata'             => $metadataV1
    ];
}

// -------------------- cURL Request --------------------
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$apiToken}",
        "Content-Type: application/json"
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_SSL_VERIFYPEER => false, // For local dev only. Enable in production!
    CURLOPT_HEADER => false,
]);

$response = curl_exec($ch);

// Transport errors
if (curl_errno($ch)) {
    if ($OUTPUT_RAW_JSON) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'curl_error', 'message' => curl_error($ch)], JSON_UNESCAPED_SLASHES);
        curl_close($ch);
        exit;
    } else {
        echo "cURL Error: " . curl_error($ch);
        curl_close($ch);
        exit;
    }
}

$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// -------------------- Handle Response --------------------
if ($OUTPUT_RAW_JSON) {
    header('Content-Type: application/json');
    if (in_array($httpStatus, [200, 201], true)) {
        echo $response;
    } else {
        echo json_encode([
            'error'       => 'non_success_status',
            'httpStatus'  => $httpStatus,
            'rawResponse' => $response
        ], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

// Human-readable branch
if (in_array($httpStatus, [200, 201], true)) {
    $json = json_decode($response, true);
    echo "✅ Payout initiated successfully! [version={$API_VERSION}]\n";
    echo "HTTP Status: {$httpStatus}\n";
    echo "Response:\n";
    print_r($json);
} else {
    echo "❌ Failed to initiate payout [version={$API_VERSION}] (HTTP {$httpStatus})\n";
    echo "Raw Response: {$response}\n";
}