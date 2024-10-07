<?php

// Configuration for the environment (sandbox or production)
$config = [
    'sandbox' => [
        'api_url' => 'https://api.sandbox.pawapay.cloud/deposits/',
        'api_token' => 'your_sandbox_api_token_here' // Replace with your real sandbox token
    ],
    'production' => [
        'api_url' => 'https://api.pawapay.cloud/deposits/',
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

// The deposit ID for which the status needs to be checked
$depositId = "deposit ID here";  // Replace with the deposit ID you want to check

// Prepare the cURL session for a GET request
$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl . $depositId,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ],
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_SSL_VERIFYPEER => false
]);

// Execute the request and capture the response
$response = curl_exec($curl);

// Handle any cURL errors
if (curl_errno($curl)) {
    echo "cURL Error: " . curl_error($curl);
} else {
    // Get HTTP status code of the request
    $httpStatusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    // Process the response based on the HTTP status code
    if ($httpStatusCode === 200) {
        echo "Deposit status retrieved successfully!\n";
        echo "Response: " . $response;  // Print the full response
    } else {
        echo "Failed to retrieve deposit status. HTTP Status Code: $httpStatusCode\n";
        echo "Response: " . $response;  // Print the full response for debugging
    }
}

// Close the cURL session
curl_close($curl);