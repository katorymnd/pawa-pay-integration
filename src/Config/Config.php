<?php

namespace Katorymnd\PawaPayIntegration\Config;

/**
 * The Config class provides configuration settings for different environments (sandbox and production).
 *
 * - 'sandbox': Used for testing and development purposes. This points to pawaPay's sandbox API.
 * - 'production': Used for real, live transactions. This points to pawaPay's live API.
 *
 * These settings are accessed by the ApiClient class to determine the correct API URL based on the environment
 * (either 'sandbox' for testing or 'production' for live usage).
 *
 * Example Usage:
 * The ApiClient constructor will choose the correct API URL based on the environment parameter passed when creating
 * a new instance, and use the corresponding URL for making requests to pawaPay.
 */
class Config
{
    public static $settings = [
        'sandbox' => [
            'api_url' => 'https://api.sandbox.pawapay.io'  // Sandbox URL for testing
        ],
        'production' => [
            'api_url' => 'https://api.pawapay.io'  // Production URL for live transactions
        ]
    ];
}