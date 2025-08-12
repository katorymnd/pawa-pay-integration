<?php
/**
 * Create a Payment Page session with pawaPay (V1 Widget or V2 Payment Page)
 *
 * What this script does
 *  1) Generates a valid UUIDv4 for the depositId
 *  2) Lets you choose environment (sandbox or production)
 *  3) Lets you choose API version:
 *       - V1 -> POST /v1/widget/sessions
 *       - V2 -> POST /v2/paymentpage
 *  4) Sends the request and prints the API response
 *
 * Why two versions
 *  - V1 is the classic widget flow, very simple payload.
 *  - V2 is the newer Payment Page flow with richer fields, especially amountDetails and phoneNumber,
 *    plus clearer semantics around country and customerMessage. It also returns a redirectUrl you must
 *    forward the customer to within 15 minutes.
 *
 * Tips for use (practical)
 *  - Flip $API_VERSION between 'v1' and 'v2' to compare behavior without touching the rest.
 *  - Set $OUTPUT_RAW_JSON = true to print the exact raw JSON returned by pawaPay, no extra text at all,
 *    which helps with signature validation and logging.
 *  - In production, set CURLOPT_SSL_VERIFYPEER to true.
 */

// -------------------- Helper: Generate UUID v4 --------------------
/**
 * Generate a UUID v4 string suitable for depositId
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

// Choose API version: 'v1' or 'v2' (default to 'v1' to preserve old behavior)
$API_VERSION = 'v1';

// -------------------- Configuration --------------------
/**
 * We keep one config entry per environment. For backward-compatibility, the 'api_url' points to
 * the V1 widget endpoint. We rewrite the endpoint if you pick V2.
 */
$config = [
    'sandbox' => [
        'api_url'   => 'https://api.sandbox.pawapay.io/v1/widget/sessions',
        'api_token' => 'your_sandbox_api_token_here'
    ],
    'production' => [
        'api_url'   => 'https://api.pawapay.io/v1/widget/sessions',
        'api_token' => 'your_production_api_token_here'
    ]
];

// Validate environment
if (!isset($config[$environment])) {
    die("Invalid environment. Use 'sandbox' or 'production'.");
}

$baseUrl  = $config[$environment]['api_url'];
$apiToken = $config[$environment]['api_token'];

// -------------------- Endpoint selection (surgical) --------------------
/**
 * Derive the final endpoint from the configured URL and the selected API version.
 * - If config points to the V1 widget path, we replace it with the V2 paymentpage path when needed.
 * - If config is changed in the future to be a bare host, we’ll append the correct path.
 */
function resolveEndpoint(string $configuredUrl, string $version): string
{
    // If it ends with the V1 widget path, switch to V2 paymentpage when requested.
    if ($version === 'v2') {
        // Replace known V1 tail with V2 tail
        $candidate = preg_replace('#/v1/widget/sessions$#', '/v2/paymentpage', $configuredUrl);
        if ($candidate !== null && $candidate !== $configuredUrl) {
            return $candidate;
        }
        // If the URL didn’t match the exact old tail, try to build from a base host
        $parts = parse_url($configuredUrl);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $host = $parts['scheme'] . '://' . $parts['host'];
            return $host . '/v2/paymentpage';
        }
        // Fallback
        return rtrim($configuredUrl, '/') . '/v2/paymentpage';
    }

    // Version v1
    // If already the v1 path, keep it. Otherwise, try to append it.
    if (preg_match('#/v1/widget/sessions$#', $configuredUrl)) {
        return $configuredUrl;
    }
    $parts = parse_url($configuredUrl);
    if (!empty($parts['scheme']) && !empty($parts['host'])) {
        $host = $parts['scheme'] . '://' . $parts['host'];
        return $host . '/v1/widget/sessions';
    }
    return rtrim($configuredUrl, '/') . '/v1/widget/sessions';
}

$apiUrl = resolveEndpoint($baseUrl, $API_VERSION);

// -------------------- Build Payload --------------------
$depositId = generateUuidV4();

/**
 * Provide a single set of inputs and map them per version.
 * - V1 fields: statementDescription, amount, msisdn, language, country, reason, metadata[{fieldName,fieldValue,isPII?}]
 * - V2 fields: customerMessage, amountDetails{amount,currency}, phoneNumber, language, country, reason,
 *              metadata[{orderId:.., isPII?:bool}, ...]
 */
$common = [
    'depositId'            => $depositId,
    'returnUrl'            => 'https://example.com/paymentProcessed', // Change to your return URL
    'customerMessage'      => 'OrderPayment123',  // V2 name, 4-22 chars. For V1 we’ll map to statementDescription
    'amount'               => '1000',
    'currency'             => 'UGX',             // Required for V2 amountDetails
    'msisdn'               => '256783456789',    // Optional; in V2 becomes 'phoneNumber'
    'language'             => 'EN',              // EN or FR
    'country'              => 'UGA',             // ISO Alpha-3
    'reason'               => 'Payment for order',
    // V1-shaped metadata by default; we will map to V2 if needed
    'metadata_v1' => [
        [
            'fieldName'  => 'orderId',
            'fieldValue' => 'ORD-123456789'
        ],
        [
            'fieldName'  => 'customerId',
            'fieldValue' => 'customer@email.com',
            'isPII'      => true
        ]
    ]
];

/**
 * Map V1 metadata -> V2 metadata.
 * If you already have V2-style metadata, you can skip this or adjust as needed.
 */
function mapMetadataV1ToV2(array $metaV1): array
{
    $out = [];
    foreach ($metaV1 as $row) {
        if (isset($row['fieldName'], $row['fieldValue'])) {
            $key = $row['fieldName'];
            $val = $row['fieldValue'];
            $item = [$key => $val];
            if (isset($row['isPII'])) {
                $item['isPII'] = (bool)$row['isPII'];
            }
            $out[] = $item;
        }
    }
    return $out;
}

// Construct payload per version
if ($API_VERSION === 'v2') {
    // --- V2 Payload ---
    $data = [
        'depositId'       => $common['depositId'],
        'returnUrl'       => $common['returnUrl'],
        'customerMessage' => $common['customerMessage'], // shown to customer
        'amountDetails'   => [
            'amount'   => $common['amount'],
            'currency' => $common['currency'],
        ],
        // Only include if you want to lock the payer number, else omit to let the customer enter it
        'phoneNumber'     => $common['msisdn'],
        'language'        => $common['language'],
        // Include to restrict the customer's selectable country, else omit for all configured countries
        'country'         => $common['country'],
        'reason'          => $common['reason'],
        'metadata'        => mapMetadataV1ToV2($common['metadata_v1']),
    ];
} else {
    // --- V1 Payload ---
    $data = [
        'depositId'             => $common['depositId'],
        'returnUrl'             => $common['returnUrl'],
        'statementDescription'  => $common['customerMessage'], // map from V2 name to V1 field
        'amount'                => $common['amount'],
        'msisdn'                => $common['msisdn'],
        'language'              => $common['language'],
        'country'               => $common['country'],
        'reason'                => $common['reason'],
        'metadata'              => $common['metadata_v1'],
    ];
}

// -------------------- cURL Request --------------------
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_SLASHES),
    CURLOPT_SSL_VERIFYPEER => false, // For dev only. Enable in production!
    CURLOPT_HEADER => false,
]);

$response = curl_exec($ch);

// Error check
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
if (in_array($httpStatus, [200, 201], true)) {
    if ($OUTPUT_RAW_JSON) {
        header('Content-Type: application/json');
        echo $response;
        exit;
    } else {
        $json = json_decode($response, true);
        echo "✅ Payment Page session created successfully!\n";
        echo "HTTP Status: $httpStatus\n";
        echo "Full Response:\n";
        print_r($json);

        // V1 and V2 both return a redirect URL on success. Docs emphasize it in V2.
        if (isset($json['redirectUrl'])) {
            echo "\nDeposit ID: {$common['depositId']}\n";
            echo "Redirect URL:\n" . $json['redirectUrl'] . "\n";
            echo "➡ Forward the customer to this URL within 15 minutes to complete payment.\n";
        }

        // V2 might return failure details even with 200, e.g. status: REJECTED
        if (isset($json['status']) && strtoupper($json['status']) !== 'ACCEPTED' && isset($json['failureReason'])) {
            echo "\nStatus: {$json['status']}\n";
            echo "Failure Code: " . ($json['failureReason']['failureCode'] ?? 'N/A') . "\n";
            echo "Failure Message: " . ($json['failureReason']['failureMessage'] ?? 'N/A') . "\n";
        }
    }
} else {
    if ($OUTPUT_RAW_JSON) {
        header('Content-Type: application/json');
        echo json_encode([
            'error'       => 'non_success_status',
            'httpStatus'  => $httpStatus,
            'rawResponse' => $response
        ], JSON_UNESCAPED_SLASHES);
        exit;
    } else {
        echo "❌ Failed to create Payment Page session (HTTP $httpStatus)\n";
        echo "Raw Response: $response\n";
    }
}