<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;

class ApiClientTest extends TestCase
{
    protected $apiClient;
    protected $httpClientMock;

    protected function setUp(): void
    {
        // Mock the GuzzleHttp Client
        $this->httpClientMock = $this->createMock(Client::class);

        // Instantiate the ApiClient with the mocked GuzzleHttp client
        $this->apiClient = new ApiClient('test_api_token', 'sandbox', true);

        // Use reflection to set the mocked client
        $reflection = new \ReflectionClass($this->apiClient);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($this->apiClient, $this->httpClientMock);
    }

    /**
     * Unit test for initiating deposits
     * Ensures that a deposit request returns the expected depositId in the response.
     */
    public function testInitiateDeposit()
    {
        // Set up the expected API response
        $expectedResponse = new Response(200, [], json_encode([
            'depositId' => '12345'
        ]));

        // Mock the request method to return the expected response
        $this->httpClientMock
            ->expects($this->once())  // Ensure this method is called exactly once
            ->method('request')
            ->with('POST', $this->anything())  // You can refine this condition if necessary
            ->willReturn($expectedResponse);

        // Call the initiateDeposit method
        $response = $this->apiClient->initiateDeposit('12345', 100, 'USD', 'MTN_MOMO', '256700123456');

        // Assert that the response contains the expected data
        $this->assertEquals(200, $response['status']);
        $this->assertEquals('12345', $response['response']['depositId']);
    }

    /**
     * Unit test for initiating payouts
     * Ensures that a payout request returns the expected payoutId in the response.
     */
    public function testInitiatePayout()
    {
        // Set up the expected API response
        $expectedResponse = new Response(200, [], json_encode([
            'payoutId' => '67890'
        ]));

        // Mock the request method to return the expected response
        $this->httpClientMock
            ->expects($this->once())  // Ensure this method is called exactly once
            ->method('request')
            ->with('POST', $this->anything())
            ->willReturn($expectedResponse);

        // Call the initiatePayout method
        $response = $this->apiClient->initiatePayout('67890', 200, 'USD', 'MTN_MOMO', '256700123456');

        // Assert that the response contains the expected data
        $this->assertEquals(200, $response['status']);
        $this->assertEquals('67890', $response['response']['payoutId']);
    }

    /**
     * Unit test for initiating refunds
     * Ensures that a refund request returns the expected refundId in the response.
     */
    public function testInitiateRefund()
    {
        // Set up the expected API response
        $expectedResponse = new Response(200, [], json_encode([
            'refundId' => '54321'
        ]));

        // Mock the request method to return the expected response
        $this->httpClientMock
            ->expects($this->once())
            ->method('request')
            ->with('POST', $this->anything())
            ->willReturn($expectedResponse);

        // Call the initiateRefund method
        $response = $this->apiClient->initiateRefund('54321', '12345', 50, []);

        // Assert that the response contains the expected data
        $this->assertEquals(200, $response['status']);
        $this->assertEquals('54321', $response['response']['refundId']);
    }

    /**
     * Unit test for checking transaction status
     * Ensures that the transaction status check returns the expected transactionId in the response.
     */
    public function testCheckTransactionStatus()
    {
        // Set up the expected API response for a deposit transaction
        $expectedResponse = new Response(200, [], json_encode([
            'transactionId' => 'abc123'
        ]));

        // Mock the request method to return the expected response
        $this->httpClientMock
            ->expects($this->once())
            ->method('request')
            ->with('GET', $this->anything())
            ->willReturn($expectedResponse);

        // Call the checkTransactionStatus method
        $response = $this->apiClient->checkTransactionStatus('abc123', 'deposit');

        // Assert that the response contains the expected data
        $this->assertEquals(200, $response['status']);
        $this->assertEquals('abc123', $response['response']['transactionId']);
    }

    /**
     * Unit test for SSL verification
     * Ensures that SSL verification is correctly enabled for production and disabled for sandbox environments.
     */
    public function testSSLVerification()
    {
        // Test with SSL verification turned on (production)
        $apiClientWithSSL = new ApiClient('test_api_token', 'production', true);
        $reflection = new \ReflectionClass($apiClientWithSSL);
        $sslVerifyProperty = $reflection->getProperty('sslVerify');
        $sslVerifyProperty->setAccessible(true);
        $this->assertTrue($sslVerifyProperty->getValue($apiClientWithSSL));

        // Test with SSL verification turned off (sandbox)
        $apiClientWithoutSSL = new ApiClient('test_api_token', 'sandbox', false);
        $sslVerifyProperty = $reflection->getProperty('sslVerify');
        $sslVerifyProperty->setAccessible(true);
        $this->assertFalse($sslVerifyProperty->getValue($apiClientWithoutSSL));
    }
}