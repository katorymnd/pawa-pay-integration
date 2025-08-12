<?php

// Function to generate a valid UUID (version 4) for the depositId
function generateUuidV4()
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

// Configuration for the environment (sandbox or production)
$config = [
    'sandbox' => [
        'api_url' => 'https://api.sandbox.pawapay.io',
        'api_token' => 'your_sandbox_api_token' // Replace with your real sandbox token
    ],
    'production' => [
        'api_url' => 'https://api.pawapay.io',
        'api_token' => 'your_production_api_token' // Replace with your real production token
    ]
];

// Choose environment: 'sandbox' or 'production'
$environment = 'sandbox'; // Change to 'production' when ready
// Choose API version: 'v1' or 'v2'
$apiVersion = 'v1'; // Default to v1

// Validate environment configuration
if (!isset($config[$environment])) {
    die("Invalid environment selected. Please choose 'sandbox' or 'production'.");
}

// Fetch the config based on environment
$apiBaseUrl = $config[$environment]['api_url'];
$apiToken   = $config[$environment]['api_token'];

// Endpoint based on version
$endpoint = ($apiVersion === 'v2') ? '/v2/deposits' : '/deposits';
$apiUrl   = $apiBaseUrl . $endpoint;

// Prepare the request payload (data to be sent)
$depositId = generateUuidV4();  // Generate a valid UUID for the depositId

if ($apiVersion === 'v2') {
    // --- V2 Payload ---
    $data = [
        'depositId' => $depositId,
        'payer' => [
            'type' => 'MMO',
            'accountDetails' => [
                'phoneNumber' => '256783456789', // Payer's phone number
                'provider'    => 'MTN_MOMO_UGA'  // Provider code
            ]
        ],
        'amount'             => '1000',
        'currency'           => 'UGX',
        'customerMessage'    => 'Payment for order',
        'clientReferenceId'  => 'INV-123456', // Optional reference
        'metadata' => [
            ['orderId' => 'ORD-123456789'],
            ['customerId' => 'customer@example.com', 'isPII' => true]
        ]
    ];
} else {
    // --- V1 Payload ---
    $data = [
        'depositId' => $depositId,
        'amount'    => 1000,   // Amount in the specified currency
        'currency'  => 'UGX',  // Currency code
        'correspondent' => 'MTN_MOMO_UGA', // Correspondent ID
        'payer' => [
            'type' => 'MSISDN',
            'address' => [
                'value' => '256783456789' // Payer's phone number
            ]
        ],
        'customerTimestamp'    => (new DateTime())->format(DateTime::ATOM),
        'statementDescription' => 'Payment for order'
    ];
}

// Initialize the cURL session
$curl = curl_init($apiUrl);

// Set cURL options
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $apiToken",
    "Content-Type: application/json"
]);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));

// SSL verification (disable only for local development)
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

// Execute the request
$response = curl_exec($curl);

// Handle cURL errors
if (curl_errno($curl)) {
    echo "cURL Error: " . curl_error($curl);
    curl_close($curl);
    exit();
}

// Get HTTP status code
$httpStatusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

// Close cURL
curl_close($curl);

// Output result
if ($httpStatusCode === 200) {
    echo "Deposit initiated successfully! [API Version: $apiVersion]\n";
    echo "Response: " . $response;
} else {
    echo "Failed to initiate deposit. HTTP Status Code: $httpStatusCode [API Version: $apiVersion]\n";
    echo "Response: " . $response;
}