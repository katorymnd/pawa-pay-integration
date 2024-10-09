<?php

// Configuration for the environment (sandbox or production)
$config = [
    'sandbox' => [
        'api_url' => 'https://api.sandbox.pawapay.io/payouts/',
        'api_token' => 'your_sandbox_api_token_here' // Replace with your real sandbox token
    ],
    'production' => [
        'api_url' => 'https://api.pawapay.io/payouts/',
        'api_token' => 'your_production_api_token' // Replace with your real production token
    ]
];

// Choose environment: 'sandbox' or 'production'
$environment = 'sandbox';  // Change this to 'production' when moving to production

// Validate environment configuration
if (!isset($config[$environment])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => "Invalid environment selected. Please choose 'sandbox' or 'production'."
    ]);
    exit;
}

// Fetch the config based on environment
$apiUrl = rtrim($config[$environment]['api_url'], '/') . '/';
$apiToken = $config[$environment]['api_token'];

// The payout ID for which the status needs to be checked
$payoutId = "payoutID here";  // Replace with the payout ID you want to check

// Prepare the cURL session for a GET request
$curl = curl_init();

// Set cURL options
curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl . $payoutId,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $apiToken",
        "Content-Type: application/json"
    ],
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_SSL_VERIFYPEER => ($environment === 'production') ? true : false, // Enable SSL verification in production
    CURLOPT_CONNECTTIMEOUT => 10, // Timeout after 10 seconds
    CURLOPT_TIMEOUT => 30, // Maximum time for the request
]);

// Execute the request and capture the response
$response = curl_exec($curl);

// Handle any cURL errors
if (curl_errno($curl)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => "cURL Error: " . curl_error($curl)
    ]);
} else {
    // Get HTTP status code of the request
    $httpStatusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    // Decode the JSON response
    $decodedResponse = json_decode($response, true);

    // Process the response based on the HTTP status code
    if ($httpStatusCode === 200) {
        // Successful response
        echo json_encode([
            'success' => true,
            'message' => "Payout status retrieved successfully!",
            'data' => $decodedResponse
        ], JSON_PRETTY_PRINT);
    } else {
        // Failed response
        echo json_encode([
            'success' => false,
            'message' => "Failed to retrieve payout status. HTTP Status Code: $httpStatusCode",
            'error' => $decodedResponse
        ], JSON_PRETTY_PRINT);
    }
}

// Close the cURL session
curl_close($curl);