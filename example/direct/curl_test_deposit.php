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
        'api_url' => 'https://api.sandbox.pawapay.cloud/deposits',
        'api_token' => 'your_sandbox_api_token_here' // Replace with your real sandbox token
    ],
    'production' => [
        'api_url' => 'https://api.pawapay.cloud/deposits',
        'api_token' => 'your_production_api_token' // Replace with your real production token
    ]
];

// Choose environment: 'sandbox' or 'production'
$environment = 'sandbox';  // Change this to 'production' when moving to production

// Validate environment configuration
if (!isset($config[$environment])) {
    die("Invalid environment selected. Please choose 'sandbox' or 'production'.");
}

// Fetch the config based on environment
$apiUrl = $config[$environment]['api_url'];
$apiToken = $config[$environment]['api_token'];

// Prepare the request payload (data to be sent)
$depositId = generateUuidV4();  // Generate a valid UUID for the depositId
$data = [
    'depositId' => $depositId,
    'amount' => 1000,  // Amount in the specified currency
    'currency' => 'UGX',  // Currency code (UGX for Uganda)
    'correspondent' => 'MTN_MOMO_UGA',  // Correspondent ID (MTN Uganda)
    'payer' => [
        'type' => 'MSISDN',
        'address' => [
            'value' => '256783456789'  // Payer's phone number in international format
        ]
    ],
    'customerTimestamp' => (new DateTime())->format(DateTime::ATOM),  // Timestamp
    'statementDescription' => 'Payment for order'
];

// Initialize the cURL session
$curl = curl_init($apiUrl);

// Set cURL options
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);  // Return the response as a string
curl_setopt($curl, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $apiToken",  // Set Authorization header with the API token
    "Content-Type: application/json"  // Set content type to JSON
]);
curl_setopt($curl, CURLOPT_POST, true);  // This is a POST request
curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));  // Set the payload as JSON

// SSL verification
// In a production environment, remove the line below to enable SSL verification
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);  // Disable SSL certificate verification for local dev only

// Execute the request and capture the response
$response = curl_exec($curl);

// Handle any cURL errors
if (curl_errno($curl)) {
    echo "cURL Error: " . curl_error($curl);
    curl_close($curl);
    exit();
}

// Get HTTP status code of the request
$httpStatusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

// Close the cURL session
curl_close($curl);

// Process the response based on the HTTP status code
if ($httpStatusCode === 200) {
    echo "Deposit initiated successfully!\n";
    echo "Response: " . $response;  // Print the full response
} else {
    echo "Failed to initiate deposit. HTTP Status Code: $httpStatusCode\n";
    echo "Response: " . $response;  // Print the full response for debugging
}