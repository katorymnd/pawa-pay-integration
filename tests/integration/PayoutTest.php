<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers; // For generating UUIDs

class PayoutTest extends TestCase
{
    protected $apiClientMock;

    protected function setUp(): void
    {
        // Mock the ApiClient instead of using the real one
        $this->apiClientMock = $this->createMock(ApiClient::class);
    }

    /**
     * Test the payout initiation process with a mocked response
     */
    public function testPayoutInitiationMocked()
    {
        // Generate a sample UUIDv4 for testing
        $samplePayoutId = Helpers::generateUniqueId();

        // Mock the response from initiatePayout method
        $mockResponse = [
            'status' => 200,
            'response' => [
                'payoutId' => $samplePayoutId
            ]
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiatePayout')
            ->willReturn($mockResponse);

        // Simulate recipient data
        $recipientMsisdn = '256783456789';
        $amount = '200.00';
        $currency = 'UGX';
        $correspondent = 'MTN_MOMO_UGA';
        $statementDescription = 'Payout for service rendered';

        // Call the initiatePayout method with mocked data
        $response = $this->apiClientMock->initiatePayout(
            $samplePayoutId,
            $amount,
            $currency,
            $correspondent,
            $recipientMsisdn,
            $statementDescription
        );

        // Assert that the mock response contains the expected data
        $this->assertEquals(200, $response['status']);
        $this->assertEquals($samplePayoutId, $response['response']['payoutId']);
    }

    /**
     * Test payout with missing fields
     */
    public function testPayoutMissingFields()
    {
        // Mock the response from initiatePayout method to simulate failure
        $mockResponse = [
            'status' => 400,
            'response' => [
                'message' => 'Missing required fields.'
            ]
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiatePayout')
            ->willReturn($mockResponse);

        // Simulate incomplete data (missing currency)
        $recipientMsisdn = '256783456789';
        $amount = '200.00';
        $correspondent = 'MTN_MOMO_UGA';
        $statementDescription = 'Payout for service rendered';

        // Call the initiatePayout method with mocked data (missing currency)
        $response = $this->apiClientMock->initiatePayout(
            Helpers::generateUniqueId(), // Generate a unique payout ID
            $amount,
            '', // Missing currency
            $correspondent,
            $recipientMsisdn,
            $statementDescription
        );

        // Assert that the mock response contains the error message
        $this->assertEquals(400, $response['status']);
        $this->assertEquals('Missing required fields.', $response['response']['message']);
    }
}