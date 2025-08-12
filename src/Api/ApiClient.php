<?php
// File: src/Api/ApiClient.php

namespace Katorymnd\PawaPayIntegration\Api;

use GuzzleHttp\Client;
use Katorymnd\PawaPayIntegration\Config\Config;

/**
 * ApiClient
 *
 * Purpose
 * - Backwards compatible pawaPay client with dual-stack V1 and V2.
 * - Default behavior is V1. You can switch to V2 per instance or per call.
 *
 * Highlights
 * - Deposits
 *   - V1: initiateDeposit(...)
 *   - V2: initiateDepositV2(...)
 *   - Auto: initiateDepositAuto([...]) routes by $apiVersion
 * - Refunds
 *   - V1: initiateRefund($refundId, $depositId, $amount, $metadata)
 *   - V2: initiateRefundV2($refundId, $depositId, $amount, $currency, array $metadata)
 *   - Auto: initiateRefundAuto([...]) routes by $apiVersion
 * - Payouts
 *   - V1: initiatePayout(...)
 *   - V2: initiatePayoutV2(...)
 *   - Auto: initiatePayoutAuto([...]) routes by $apiVersion

 *
 * Notes
 * - Base URL comes from Config as-is.
 * - Signature-related headers are controlled by your dashboard settings.
 * - For GET requests, pass query parameters via $data; we map them to 'query' in Guzzle.
 */
class ApiClient
{
    /** @var string */
    private $apiToken;

    /** @var string */
    private $apiBaseUrl;

    /** @var Client */
    private $httpClient;

    /** @var bool */
    private $sslVerify;

    /** @var string 'v1'|'v2' */
    private $apiVersion;

    /**
     * Constructor
     *
     * @param string $apiToken     Bearer token
     * @param string $environment  'sandbox' or 'production'
     * @param bool   $sslVerify    Enable TLS verification
     * @param string $apiVersion   'v1' (default) or 'v2'
     *
     * @throws \Exception
     */
    public function __construct($apiToken, $environment = 'sandbox', $sslVerify = true, $apiVersion = 'v1')
    {
        $this->apiToken   = $apiToken;
        $this->apiBaseUrl = $this->setBaseUrl($environment);
        $this->httpClient = new Client();
        $this->sslVerify  = $sslVerify;
        $this->apiVersion = \in_array($apiVersion, ['v1', 'v2'], true) ? $apiVersion : 'v1';
    }

    /**
     * Resolve base URL from Config.
     *
     * @param string $environment
     * @return string
     * @throws \Exception
     */
    private function setBaseUrl($environment)
    {
        if (!isset(Config::$settings[$environment]['api_url'])) {
            throw new \Exception("Invalid environment specified");
        }
        return Config::$settings[$environment]['api_url'];
    }

    /**
     * Low level HTTP wrapper.
     * If $endpoint starts with '/', it is appended to $apiBaseUrl.
     *
     * @param string     $endpoint  path beginning with '/'
     * @param string     $method    'GET' or 'POST'
     * @param array|null $data      POST json body or GET query params
     * @return array{status:int,response:array|null}
     * @throws \Exception
     */
    private function makeApiRequest($endpoint, $method = 'POST', $data = null)
    {
        $url = $this->apiBaseUrl . $endpoint;

        $options = [
            'headers' => [
                'Authorization' => "Bearer {$this->apiToken}",
                'Content-Type'  => 'application/json',
            ],
            'verify' => $this->sslVerify,
        ];

        if ($method === 'POST' && $data !== null) {
            $options['json'] = $data;
        } elseif ($method === 'GET' && $data !== null) {
            $options['query'] = $data;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            return [
                'status'   => $response->getStatusCode(),
                'response' => json_decode($response->getBody()->getContents(), true),
            ];
        } catch (\Exception $e) {
            throw new \Exception('Request Error: ' . $e->getMessage());
        }
    }

    /**
     * -----------------------------
     * V1 - Deposits - preserved
     * -----------------------------
     * Maps to POST /deposits.
     *
     * @param string $depositId
     * @param string $amount
     * @param string $currency
     * @param string $correspondent
     * @param string $payer
     * @param string $statementDescription
     * @param array  $metadata
     * @return array
     */
    public function initiateDeposit(
        $depositId,
        $amount,
        $currency,
        $correspondent,
        $payer,
        $statementDescription = 'Payment for order',
        $metadata = []
    ) {
        $data = [
            'depositId' => $depositId,
            'amount'    => $amount,
            'currency'  => $currency,
            'correspondent' => $correspondent,
            'payer' => [
                'type'    => 'MSISDN',
                'address' => ['value' => $payer],
            ],
            'customerTimestamp'    => (new \DateTime())->format(\DateTime::ATOM),
            'statementDescription' => $statementDescription,
        ];

        if (!empty($metadata)) {
            $data['metadata'] = $metadata;
        }

        return $this->makeApiRequest('/deposits', 'POST', $data);
    }

    /**
     * -----------------------------
     * V2 - Deposits - explicit
     * -----------------------------
     * Maps to POST /v2/deposits.
     *
     * @param string      $depositId
     * @param string      $amount
     * @param string      $currency
     * @param string      $payerMsisdn
     * @param string      $provider
     * @param string|null $customerMessage
     * @param string|null $clientReferenceId
     * @param string|null $preAuthorisationCode
     * @param array       $metadata
     * @return array
     */
    public function initiateDepositV2(
        string $depositId,
        string $amount,
        string $currency,
        string $payerMsisdn,
        string $provider,
        ?string $customerMessage = null,
        ?string $clientReferenceId = null,
        ?string $preAuthorisationCode = null,
        array $metadata = []
    ) {
        $payload = [
            'depositId' => $depositId,
            'payer' => [
                'type' => 'MMO',
                'accountDetails' => [
                    'phoneNumber' => $payerMsisdn,
                    'provider'    => $provider,
                ],
            ],
            'amount'   => $amount,
            'currency' => $currency,
        ];

        if ($customerMessage !== null && $customerMessage !== '') {
            $payload['customerMessage'] = $customerMessage;
        }
        if ($clientReferenceId !== null && $clientReferenceId !== '') {
            $payload['clientReferenceId'] = $clientReferenceId;
        }
        if ($preAuthorisationCode !== null && $preAuthorisationCode !== '') {
            $payload['preAuthorisationCode'] = $preAuthorisationCode;
        }
        if (!empty($metadata)) {
            $payload['metadata'] = array_values($metadata);
        }

        return $this->makeApiRequest('/v2/deposits', 'POST', $payload);
    }

    /**
     * Version-aware convenience for deposits.
     * - If apiVersion is 'v2', calls initiateDepositV2.
     * - Otherwise calls initiateDeposit (V1).
     *
     * Expected keys for V1:
     *   depositId, amount, currency, correspondent, payerMsisdn, statementDescription?, metadata?
     *
     * Expected keys for V2:
     *   depositId, amount, currency, payerMsisdn, provider, customerMessage?, clientReferenceId?, preAuthorisationCode?, metadata?
     *
     * @param array $args
     * @return array
     */
    public function initiateDepositAuto(array $args)
    {
        if ($this->apiVersion === 'v2') {
            return $this->initiateDepositV2(
                $args['depositId'],
                $args['amount'],
                $args['currency'],
                $args['payerMsisdn'],
                $args['provider'],
                $args['customerMessage']        ?? null,
                $args['clientReferenceId']      ?? null,
                $args['preAuthorisationCode']   ?? null,
                $args['metadata']               ?? []
            );
        }

        return $this->initiateDeposit(
            $args['depositId'],
            $args['amount'],
            $args['currency'],
            $args['correspondent'],
            $args['payerMsisdn'],
            $args['statementDescription'] ?? 'Payment for order',
            $args['metadata']             ?? []
        );
    }

    /**
     * Payout - V1 shape, unchanged.
     * Maps to POST /payouts.
     *
     * @param string $payoutId
     * @param string $amount
     * @param string $currency
     * @param string $correspondent
     * @param string $recipient
     * @param string $statementDescription
     * @param array  $metadata
     * @return array
     */
    public function initiatePayout($payoutId, $amount, $currency, $correspondent, $recipient, $statementDescription = 'Payout to customer', $metadata = [])
    {
        $data = [
            'payoutId' => $payoutId,
            'amount'   => $amount,
            'currency' => $currency,
            'correspondent' => $correspondent,
            'recipient' => [
                'type'    => 'MSISDN',
                'address' => ['value' => $recipient],
            ],
            'customerTimestamp'    => (new \DateTime())->format(\DateTime::ATOM),
            'statementDescription' => $statementDescription,
        ];

        if (!empty($metadata)) {
            $data['metadata'] = $metadata;
        }

        return $this->makeApiRequest('/payouts', 'POST', $data);
    }

    /**
     * Payout - V2, explicit.
     * Maps to POST /v2/payouts.
     *
     * Accepts V1-style metadata items (fieldName/fieldValue) and normalizes to V2 objects.
     *
     * @param string      $payoutId
     * @param string      $amount
     * @param string      $currency
     * @param string      $recipientMsisdn
     * @param string      $provider
     * @param string|null $customerMessage
     * @param array       $metadata
     * @return array
     */
    public function initiatePayoutV2(
        string $payoutId,
        string $amount,
        string $currency,
        string $recipientMsisdn,
        string $provider,
        ?string $customerMessage = null,
        array $metadata = []
    ): array {
        $payload = [
            'payoutId' => $payoutId,
            'recipient' => [
                'type' => 'MMO',
                'accountDetails' => [
                    'phoneNumber' => $recipientMsisdn,
                    'provider'    => $provider,
                ],
            ],
            'amount'   => $amount,
            'currency' => $currency,
        ];

        if ($customerMessage !== null && $customerMessage !== '') {
            $payload['customerMessage'] = $customerMessage;
        }

        if (!empty($metadata)) {
            $norm = [];
            foreach ($metadata as $item) {
                if (!is_array($item)) continue;
                // V1 -> V2 transform if needed
                if (isset($item['fieldName'], $item['fieldValue'])) {
                    $obj = [(string)$item['fieldName'] => $item['fieldValue']];
                    if (array_key_exists('isPII', $item)) {
                        $obj['isPII'] = (bool)$item['isPII'];
                    }
                    $norm[] = $obj;
                } else {
                    // Already V2-like
                    $norm[] = $item;
                }
            }
            if (!empty($norm)) {
                $payload['metadata'] = array_values($norm);
            }
        }

        return $this->makeApiRequest('/v2/payouts', 'POST', $payload);
    }

    /**
     * Version-aware convenience for payouts.
     * - If apiVersion is 'v2', calls initiatePayoutV2.
     * - Otherwise calls initiatePayout (V1).
     *
     * V1 expects: payoutId, amount, currency, correspondent, recipientMsisdn, statementDescription?, metadata?
     * V2 expects: payoutId, amount, currency, recipientMsisdn, provider, customerMessage?, metadata?
     */
    public function initiatePayoutAuto(array $args): array
    {
        if ($this->apiVersion === 'v2') {
            return $this->initiatePayoutV2(
                $args['payoutId'],
                $args['amount'],
                $args['currency'],
                $args['recipientMsisdn'],
                $args['provider'],
                $args['customerMessage'] ?? null,
                $args['metadata']        ?? []
            );
        }

        return $this->initiatePayout(
            $args['payoutId'],
            $args['amount'],
            $args['currency'],
            $args['correspondent'],
            $args['recipientMsisdn'],
            $args['statementDescription'] ?? 'Payout to customer',
            $args['metadata']             ?? []
        );
    }


    /**
     * Refund - V1 shape, unchanged.
     * Maps to POST /refunds.
     *
     * @param string $refundId
     * @param string $depositId
     * @param string $amount
     * @param array  $metadata
     * @return array
     */
    public function initiateRefund($refundId, $depositId, $amount, $metadata)
    {
        $data = [
            'refundId'  => $refundId,
            'depositId' => $depositId,
            'amount'    => $amount,
            'metadata'  => $metadata,
        ];

        return $this->makeApiRequest('/refunds', 'POST', $data);
    }

    /**
     * Refund - V2, explicit.
     * Maps to POST /v2/refunds.
     *
     * Accepts V1-style metadata items, for example
     *   [{fieldName: "orderId", fieldValue: "ORD-123"}]
     * and normalizes them to V2 objects, for example
     *   [{ "orderId": "ORD-123" }]
     *
     * @param string $refundId
     * @param string $depositId
     * @param string $amount
     * @param string $currency   ISO 4217, upper case
     * @param array  $metadata   up to 10 entries, either V1-style or V2-style
     * @return array
     */
    public function initiateRefundV2(
        string $refundId,
        string $depositId,
        string $amount,
        string $currency,
        array $metadata = []
    ): array {
        $payload = [
            'refundId'  => $refundId,
            'depositId' => $depositId,
            'amount'    => $amount,
            'currency'  => $currency,
        ];

        if (!empty($metadata)) {
            $norm = [];
            foreach ($metadata as $item) {
                if (!is_array($item)) {
                    continue;
                }
                // V1 -> V2 transform
                if (isset($item['fieldName'], $item['fieldValue'])) {
                    $obj = [(string)$item['fieldName'] => $item['fieldValue']];
                    if (array_key_exists('isPII', $item)) {
                        $obj['isPII'] = (bool)$item['isPII'];
                    }
                    $norm[] = $obj;
                } else {
                    // Already V2-like
                    $norm[] = $item;
                }
            }
            if (!empty($norm)) {
                $payload['metadata'] = array_values($norm);
            }
        }

        return $this->makeApiRequest('/v2/refunds', 'POST', $payload);
    }

    /**
     * Version-aware convenience for refunds.
     * - If apiVersion is 'v2', calls initiateRefundV2. Requires currency in $args.
     * - Otherwise calls initiateRefund (V1).
     *
     * Expected keys for V1:
     *   refundId, depositId, amount, metadata?
     *
     * Expected keys for V2:
     *   refundId, depositId, amount, currency, metadata?
     *
     * @param array $args
     * @return array
     */
    public function initiateRefundAuto(array $args): array
    {
        if ($this->apiVersion === 'v2') {
            return $this->initiateRefundV2(
                $args['refundId'],
                $args['depositId'],
                $args['amount'],
                $args['currency'],
                $args['metadata'] ?? []
            );
        }

        return $this->initiateRefund(
            $args['refundId'],
            $args['depositId'],
            $args['amount'],
            $args['metadata'] ?? []
        );
    }

  

    /**
     * -----------------------------
     * Meta endpoints, V1
     * -----------------------------
     * These preserve your existing behavior and routes.
     */

    /**
     * Provider availability, V1 route.
     * Maps to GET /availability.
     *
     * @return array
     */
    public function checkMNOAvailability()
    {
        return $this->makeApiRequest('/availability', 'GET');
    }

    /**
     * Active configuration, V1 route.
     * Maps to GET /active-conf.
     *
     * @return array
     */
    public function checkActiveConf()
    {
        return $this->makeApiRequest('/active-conf', 'GET');
    }

    /**
     * -----------------------------
     * Meta endpoints, V2
     * -----------------------------
     * Fine-grained access to V2 including filters.
     */

    /**
     * Provider availability, V2 route.
     * Maps to GET /v2/availability with optional filters.
     *
     * @param string|null $country        ISO 3166-1 alpha-3 (e.g., 'ZMB')
     * @param string|null $operationType  'DEPOSIT'|'PAYOUT'|'REFUND'|'REMITTANCE'
     * @return array
     */
    public function checkMNOAvailabilityV2(?string $country = null, ?string $operationType = null)
    {
        $query = [];
        if (!empty($country)) {
            $query['country'] = $country;
        }
        if (!empty($operationType)) {
            $query['operationType'] = $operationType;
        }

        return $this->makeApiRequest('/v2/availability', 'GET', $query ?: null);
    }

    /**
     * Active configuration, V2 route.
     * Maps to GET /v2/active-conf with optional filters.
     *
     * @param string|null $country        ISO 3166-1 alpha-3 (e.g., 'ZMB')
     * @param string|null $operationType  'DEPOSIT'|'PAYOUT'|'REFUND'|'REMITTANCE'|'PUSH_DEPOSIT'|'NAME_LOOKUP'
     * @return array
     */
    public function checkActiveConfV2(?string $country = null, ?string $operationType = null)
    {
        $query = [];
        if (!empty($country)) {
            $query['country'] = $country;
        }
        if (!empty($operationType)) {
            $query['operationType'] = $operationType;
        }

        return $this->makeApiRequest('/v2/active-conf', 'GET', $query ?: null);
    }

    /**
     * Version-aware convenience, Provider Availability.
     * Routes to V2 when $apiVersion is 'v2', otherwise uses V1.
     *
     * @param string|null $country
     * @param string|null $operationType
     * @return array
     */
    public function checkMNOAvailabilityAuto(?string $country = null, ?string $operationType = null)
    {
        if ($this->apiVersion === 'v2') {
            return $this->checkMNOAvailabilityV2($country, $operationType);
        }
        // V1 does not support filters on this client method; callers can still hand-roll query if needed.
        return $this->checkMNOAvailability();
    }

    /**
     * Version-aware convenience, Active Configuration.
     * Routes to V2 when $apiVersion is 'v2', otherwise uses V1.
     *
     * @param string|null $country
     * @param string|null $operationType
     * @return array
     */
    public function checkActiveConfAuto(?string $country = null, ?string $operationType = null)
    {
        if ($this->apiVersion === 'v2') {
            return $this->checkActiveConfV2($country, $operationType);
        }
        return $this->checkActiveConf();
    }

    /**
     * Transaction status - unchanged routes (V1).
     * Maps to:
     *   deposits:   GET /deposits/{id}
     *   payouts:    GET /payouts/{id}
     *   refunds:    GET /refunds/{id}
     *   remittance: N/A in V1 (throws)
     *
     * @param string $transactionId
     * @param string $type 'deposit'|'payout'|'refund'|'remittance'
     * @return array
     */
    public function checkTransactionStatus($transactionId, $type = 'deposit')
    {
        if ($type === 'remittance') {
            throw new \InvalidArgumentException('Remittance status is only available in API v2.');
        }

        if ($type === 'payout') {
            $endpoint = "/payouts/{$transactionId}";
        } elseif ($type === 'refund') {
            $endpoint = "/refunds/{$transactionId}";
        } else {
            $endpoint = "/deposits/{$transactionId}";
        }

        return $this->makeApiRequest($endpoint, 'GET');
    }

    /**
     * V2 status check.
     * Maps to:
     *   deposits:    GET /v2/deposits/{id}
     *   payouts:     GET /v2/payouts/{id}
     *   refunds:     GET /v2/refunds/{id}
     *   remittances: GET /v2/remittances/{id}
     *
     * @param string $transactionId
     * @param string $type 'deposit'|'payout'|'refund'|'remittance'
     */
    public function checkTransactionStatusV2(string $transactionId, string $type = 'deposit'): array
    {
        if ($type === 'payout') {
            $endpoint = "/v2/payouts/{$transactionId}";
        } elseif ($type === 'refund') {
            $endpoint = "/v2/refunds/{$transactionId}";
        } elseif ($type === 'remittance') {
            $endpoint = "/v2/remittances/{$transactionId}";
        } else {
            $endpoint = "/v2/deposits/{$transactionId}";
        }
        return $this->makeApiRequest($endpoint, 'GET');
    }

    /**
     * Version-aware status check (uses $this->apiVersion).
     *
     * @param string $transactionId
     * @param string $type 'deposit'|'payout'|'refund'|'remittance'
     */
    public function checkTransactionStatusAuto(string $transactionId, string $type = 'deposit'): array
    {
        if ($this->apiVersion === 'v2') {
            return $this->checkTransactionStatusV2($transactionId, $type);
        }
        return $this->checkTransactionStatus($transactionId, $type);
    }

    /**
     * Swap out the HTTP client, useful for testing.
     *
     * @param Client $httpClient
     * @return void
     */
    public function setHttpClient(Client $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Create a Payment Page (Widget) session.
     * Endpoint: /v1/widget/sessions  auto-adjusts if base URL already ends with /v1.
     *
     * Required: depositId, returnUrl, statementDescription
     * Optional: amount, msisdn, language, country, reason, metadata
     *
     * @param array $params
     * @return array
     */
    public function createPaymentPageSession(array $params): array
    {
        foreach (['depositId', 'returnUrl', 'statementDescription'] as $k) {
            if (empty($params[$k])) {
                throw new \InvalidArgumentException("Missing required parameter: {$k}");
            }
        }

        $payload = [
            'depositId'            => (string) $params['depositId'],
            'returnUrl'            => (string) $params['returnUrl'],
            'statementDescription' => (string) $params['statementDescription'],
            'language'             => !empty($params['language']) ? (string) $params['language'] : 'EN',
        ];

        foreach (['amount', 'msisdn', 'country', 'reason'] as $k) {
            if (isset($params[$k]) && $params[$k] !== '') {
                $payload[$k] = $params[$k];
            }
        }

        if (!empty($params['metadata']) && is_array($params['metadata'])) {
            $payload['metadata'] = array_values($params['metadata']);
        }

        $base     = rtrim($this->apiBaseUrl, '/');
        $endsWith = substr($base, -3);
        $endpoint = ($endsWith === 'v1') ? '/widget/sessions' : '/v1/widget/sessions';

        return $this->makeApiRequest($endpoint, 'POST', $payload);
    }

    /**
     * Create a Payment Page session (V2).
     * Endpoint: /v2/paymentpage
     *
     * Accepts either V1-style inputs (statementDescription, msisdn + metadata as fieldName/fieldValue)
     * or V2-style inputs (customerMessage, phoneNumber + metadata as objects).
     *
     * Required: depositId, returnUrl
     * Optional: customerMessage|statementDescription, amountDetails OR (amount+currency), phoneNumber|msisdn,
     *           language, country, reason, metadata
     */
    public function createPaymentPageSessionV2(array $params): array
    {
        foreach (['depositId', 'returnUrl'] as $k) {
            if (empty($params[$k])) {
                throw new \InvalidArgumentException("Missing required parameter: {$k}");
            }
        }

        $payload = [
            'depositId' => (string) $params['depositId'],
            'returnUrl' => (string) $params['returnUrl'],
        ];

        // V1 alias -> V2 name
        if (!empty($params['customerMessage'])) {
            $payload['customerMessage'] = (string) $params['customerMessage'];
        } elseif (!empty($params['statementDescription'])) {
            $payload['customerMessage'] = (string) $params['statementDescription'];
        }

        // Amount details: accept explicit amountDetails, or (amount + currency), otherwise omit
        if (!empty($params['amountDetails']) && is_array($params['amountDetails'])) {
            $ad = $params['amountDetails'];
            if (!empty($ad['amount']) && !empty($ad['currency'])) {
                $payload['amountDetails'] = [
                    'amount'   => (string) $ad['amount'],
                    'currency' => (string) $ad['currency'],
                ];
            }
        } elseif (!empty($params['amount']) && !empty($params['currency'])) {
            $payload['amountDetails'] = [
                'amount'   => (string) $params['amount'],
                'currency' => (string) $params['currency'],
            ];
        }

        // Phone number: accept phoneNumber or msisdn
        if (!empty($params['phoneNumber'])) {
            $payload['phoneNumber'] = preg_replace('/\D/', '', (string) $params['phoneNumber']);
        } elseif (!empty($params['msisdn'])) {
            $payload['phoneNumber'] = preg_replace('/\D/', '', (string) $params['msisdn']);
        }

        if (!empty($params['language'])) $payload['language'] = (string) $params['language'];
        if (!empty($params['country']))  $payload['country']  = (string) $params['country'];
        if (!empty($params['reason']))   $payload['reason']   = (string) $params['reason'];

        // Metadata: accept V1 style [{fieldName, fieldValue, isPII?}] OR V2 style [{key: val, isPII?}]
        if (!empty($params['metadata']) && is_array($params['metadata'])) {
            $norm = [];
            foreach ($params['metadata'] as $item) {
                if (!is_array($item)) continue;

                // V1 -> V2 transform
                if (isset($item['fieldName'], $item['fieldValue'])) {
                    $obj = [(string)$item['fieldName'] => $item['fieldValue']];
                    if (array_key_exists('isPII', $item)) {
                        $obj['isPII'] = (bool) $item['isPII'];
                    }
                    $norm[] = $obj;
                } else {
                    // Already V2-like
                    $norm[] = $item;
                }
            }
            if (!empty($norm)) {
                $payload['metadata'] = array_values($norm);
            }
        }

        return $this->makeApiRequest('/v2/paymentpage', 'POST', $payload);
    }

    /**
     * Version-aware Payment Page session.
     * Routes to V2 when apiVersion='v2', else V1.
     */
    public function createPaymentPageSessionAuto(array $params): array
    {
        if ($this->apiVersion === 'v2') {
            return $this->createPaymentPageSessionV2($params);
        }
        // V1
        return $this->createPaymentPageSession($params);
    }
}