<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers; // Include the helper for UUID generation

class InitialDepositTest extends TestCase
{
    protected $apiClientMock;

    protected function setUp(): void
    {
        // Mock the ApiClient instead of using the real one
        $this->apiClientMock = $this->createMock(ApiClient::class);
    }

    /**
     * Test the deposit initiation process with a mocked response
     */
    public function testDepositInitiationMocked()
    {
        // Generate a sample UUIDv4 for testing
        $sampleUuid = Helpers::generateUniqueId(); // Assuming you have the UUIDv4 generator from Helpers class

        // Mock the response from initiateDeposit method
        $mockResponse = [
            'status' => 200,
            'response' => [
                'depositId' => $sampleUuid
            ]
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiateDeposit')
            ->willReturn($mockResponse);

        // Simulate form data
        $amount = '100.00';
        $mno = 'MTN_MOMO_UGA';
        $payerMsisdn = '256783456789';
        $description = 'Payment for order';
        $currency = 'UGX';

        // Call the initiateDeposit method with mocked data
        $response = $this->apiClientMock->initiateDeposit(
            $sampleUuid,
            $amount,
            $currency,
            $mno,
            $payerMsisdn,
            $description
        );

        // Assert that the mock response contains the expected data
        $this->assertEquals(200, $response['status']);
        $this->assertEquals($sampleUuid, $response['response']['depositId']);
    }
}