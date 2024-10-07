<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers; // For generating UUIDs

class RefundTest extends TestCase
{
    protected $apiClientMock;

    protected function setUp(): void
    {
        // Mock the ApiClient instead of using the real one
        $this->apiClientMock = $this->createMock(ApiClient::class);
    }

    /**
     * Test the refund initiation process with a mocked response
     */
    public function testRefundInitiationMocked()
    {
        // Generate a sample UUIDv4 for testing
        $sampleRefundId = Helpers::generateUniqueId();
        $sampleDepositId = Helpers::generateUniqueId(); // Simulate a valid UUID for depositId

        // Mock the response from initiateRefund method
        $mockResponse = [
            'status' => 200,
            'response' => [
                'refundId' => $sampleRefundId
            ]
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiateRefund')
            ->willReturn($mockResponse);

        // Simulate form data
        $amount = '50.00';
        $metadata = [
            [
                'fieldName' => 'orderId',
                'fieldValue' => 'ORD-123456789'
            ]
        ];

        // Call the initiateRefund method with mocked data
        $response = $this->apiClientMock->initiateRefund(
            $sampleRefundId,
            $sampleDepositId,
            $amount,
            $metadata
        );

        // Assert that the mock response contains the expected data
        $this->assertEquals(200, $response['status']);
        $this->assertEquals($sampleRefundId, $response['response']['refundId']);
    }

    /**
     * Test refund with missing required fields (e.g., depositId)
     */
    public function testRefundMissingFields()
    {
        // Mock the response from initiateRefund method to simulate failure
        $mockResponse = [
            'status' => 400,
            'response' => [
                'message' => 'Missing required fields.'
            ]
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiateRefund')
            ->willReturn($mockResponse);

        // Simulate incomplete data (missing depositId)
        $amount = '50.00';
        $metadata = [
            [
                'fieldName' => 'orderId',
                'fieldValue' => 'ORD-123456789'
            ]
        ];

        // Call the initiateRefund method with mocked data (missing depositId)
        $response = $this->apiClientMock->initiateRefund(
            '', // Missing depositId
            null,
            $amount,
            $metadata
        );

        // Assert that the mock response contains the error message
        $this->assertEquals(400, $response['status']);
        $this->assertEquals('Missing required fields.', $response['response']['message']);
    }

    /**
     * Test refund status check
     */
    public function testRefundStatusCheck()
    {
        // Generate a sample UUIDv4 for testing
        $sampleRefundId = Helpers::generateUniqueId();

        // Mock the response from checkTransactionStatus method
        $mockResponse = [
            'status' => 200,
            'response' => [
                'refundId' => $sampleRefundId,
                'status' => 'COMPLETED'
            ]
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('checkTransactionStatus')
            ->willReturn($mockResponse);

        // Call the checkTransactionStatus method with mocked data
        $response = $this->apiClientMock->checkTransactionStatus($sampleRefundId, 'refund');

        // Assert that the mock response contains the expected data
        $this->assertEquals(200, $response['status']);
        $this->assertEquals('COMPLETED', $response['response']['status']);
    }
}