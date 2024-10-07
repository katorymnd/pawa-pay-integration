<?php

namespace Katorymnd\PawaPayIntegration\Api;

use GuzzleHttp\Client;
use Katorymnd\PawaPayIntegration\Config\Config;

class ApiClient
{
    private $apiToken;
    private $apiBaseUrl;
    private $httpClient;
    private $sslVerify;  // Add this variable to store the SSL verification setting

    public function __construct($apiToken, $environment = 'sandbox', $sslVerify = true)
    {
        $this->apiToken = $apiToken;
        $this->apiBaseUrl = $this->setBaseUrl($environment);
        $this->httpClient = new Client(); // Using GuzzleHttp client
        $this->sslVerify = $sslVerify;  // Set SSL verification based on the passed argument
    }

    private function setBaseUrl($environment)
    {
        if (!isset(Config::$settings[$environment]['api_url'])) {
            throw new \Exception("Invalid environment specified");
        }
        return Config::$settings[$environment]['api_url'];
    }

    private function makeApiRequest($endpoint, $method = 'POST', $data = null)
    {
        $url = $this->apiBaseUrl . $endpoint;

        $options = [
            'headers' => [
                'Authorization' => "Bearer {$this->apiToken}",
                'Content-Type' => 'application/json',
            ],
            'verify' => $this->sslVerify, // Use the $sslVerify value
        ];


        if ($method === 'POST' && $data) {
            $options['json'] = $data;
        } elseif ($method === 'GET' && $data) {
            $options['query'] = $data;
        }


        try {
            $response = $this->httpClient->request($method, $url, $options);
            return [
                'status' => $response->getStatusCode(),
                'response' => json_decode($response->getBody()->getContents(), true)
            ];
        } catch (\Exception $e) {
            throw new \Exception('Request Error: ' . $e->getMessage());
        }
    }

    public function initiateDeposit($depositId, $amount, $currency, $correspondent, $payer, $statementDescription = 'Payment for order', $metadata = [])
    {
        $data = [
            'depositId' => $depositId,
            'amount' => $amount,
            'currency' => $currency,
            'correspondent' => $correspondent,
            'payer' => [
                'type' => 'MSISDN',
                'address' => [
                    'value' => $payer
                ]
            ],
            'customerTimestamp' => (new \DateTime())->format(\DateTime::ATOM),
            'statementDescription' => $statementDescription  // User-provided description
        ];

        // Only include metadata if it's not empty
        if (!empty($metadata)) {
            $data['metadata'] = $metadata;
        }

        return $this->makeApiRequest('/deposits', 'POST', $data);
    }

    public function initiatePayout($payoutId, $amount, $currency, $correspondent, $recipient, $statementDescription = 'Payout to customer', $metadata = [])
    {
        $data = [
            'payoutId' => $payoutId,
            'amount' => $amount,
            'currency' => $currency,
            'correspondent' => $correspondent,
            'recipient' => [
                'type' => 'MSISDN',
                'address' => [
                    'value' => $recipient
                ]
            ],
            'customerTimestamp' => (new \DateTime())->format(\DateTime::ATOM),
            'statementDescription' => $statementDescription  // User-provided description
        ];

        // Only include metadata if it's not empty
        if (!empty($metadata)) {
            $data['metadata'] = $metadata;
        }

        return $this->makeApiRequest('/payouts', 'POST', $data);
    }

    public function initiateRefund($refundId, $depositId, $amount, $metadata)
    {
        $data = [
            'refundId' => $refundId,         // Unique identifier for the refund transaction
            'depositId' => $depositId,       // Unique identifier of the deposit to refund
            'amount' => $amount,             // Amount to be refunded
            'metadata' => $metadata          // Additional metadata for the refund
        ];

        return $this->makeApiRequest('/refunds', 'POST', $data);
    }

    public function checkMNOAvailability()
    {
        return $this->makeApiRequest('/availability', 'GET');
    }

    public function checkActiveConf()
    {
        return $this->makeApiRequest('/active-conf', 'GET');
    }

    public function checkTransactionStatus($transactionId, $type = 'deposit')
    {
        // Check transaction type and set the appropriate endpoint
        if ($type === 'payout') {
            $endpoint = "/payouts/{$transactionId}";
        } elseif ($type === 'refund') {
            $endpoint = "/refunds/{$transactionId}";
        } else {
            $endpoint = "/deposits/{$transactionId}";
        }

        return $this->makeApiRequest($endpoint, 'GET');
    }

    public function setHttpClient(Client $httpClient)
    {
        $this->httpClient = $httpClient;
    }

}