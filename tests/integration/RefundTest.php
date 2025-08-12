<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers;

class RefundTest extends TestCase
{
    /** @var \PHPUnit\Framework\MockObject\MockObject&ApiClient */
    protected $apiClientMock;

    protected function setUp(): void
    {
        // Mock only the methods we stub; they all exist on ApiClient.
        $this->apiClientMock = $this->getMockBuilder(ApiClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'initiateRefund',              // v1
                'initiateRefundAuto',          // v2 auto
                'checkTransactionStatus',      // v1 status
                'checkTransactionStatusAuto',  // v2 auto status
            ])
            ->getMock();
    }

    /**
     * V1 refund initiation (original flow).
     */
    public function testRefundInitiationMocked(): void
    {
        $sampleRefundId  = Helpers::generateUniqueId();
        $sampleDepositId = Helpers::generateUniqueId();

        $mockResponse = [
            'status'   => 200,
            'response' => [
                'refundId' => $sampleRefundId,
            ],
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiateRefund')
            ->willReturn($mockResponse);

        $amount = '50.00';
        $metadata = [
            ['fieldName' => 'orderId', 'fieldValue' => 'ORD-123456789'],
        ];

        $response = $this->apiClientMock->initiateRefund(
            $sampleRefundId,
            $sampleDepositId,
            $amount,
            $metadata
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame($sampleRefundId, $response['response']['refundId']);
    }

    /**
     * V1 refund: missing required fields (e.g. depositId).
     */
    public function testRefundMissingFields(): void
    {
        $mockResponse = [
            'status'   => 400,
            'response' => [
                'message' => 'Missing required fields.',
            ],
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiateRefund')
            ->willReturn($mockResponse);

        $amount = '50.00';
        $metadata = [
            ['fieldName' => 'orderId', 'fieldValue' => 'ORD-123456789'],
        ];

        $response = $this->apiClientMock->initiateRefund(
            '',        // refundId placeholder (not relevant for this mock)
            null,      // Missing depositId
            $amount,
            $metadata
        );

        $this->assertSame(400, $response['status']);
        $this->assertSame('Missing required fields.', $response['response']['message']);
    }

    /**
     * V1 refund status check.
     */
    public function testRefundStatusCheckV1(): void
    {
        $sampleRefundId = Helpers::generateUniqueId();

        $mockResponse = [
            'status'   => 200,
            'response' => [
                'refundId' => $sampleRefundId,
                'status'   => 'COMPLETED',
            ],
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('checkTransactionStatus')
            ->with($sampleRefundId, 'refund')
            ->willReturn($mockResponse);

        $response = $this->apiClientMock->checkTransactionStatus($sampleRefundId, 'refund');

        $this->assertSame(200, $response['status']);
        $this->assertSame('COMPLETED', $response['response']['status']);
    }

    /**
     * V2 refund initiation via version-aware AUTO method.
     */
    public function testRefundInitiationV2Mocked(): void
    {
        $refundId  = Helpers::generateUniqueId();
        $depositId = Helpers::generateUniqueId();

        $argsV2 = [
            'refundId'  => $refundId,
            'depositId' => $depositId,
            'amount'    => '50',     // string in v2
            'currency'  => 'UGX',
            'metadata'  => [
                ['orderId' => 'ORD-123456789'], // already v2-like
                // you could also pass v1-style and let SDK normalize:
                // ['fieldName' => 'note', 'fieldValue' => 'unit-test'],
            ],
        ];

        $mockInitiate = [
            'status'   => 200,
            'response' => [
                'refundId' => $refundId,
                'status'   => 'ACCEPTED',
            ],
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiateRefundAuto')
            ->with($this->callback(function ($payload) use ($refundId, $depositId) {
                return is_array($payload)
                    && ($payload['refundId']  ?? null) === $refundId
                    && ($payload['depositId'] ?? null) === $depositId
                    && ($payload['currency']  ?? null) === 'UGX';
            }))
            ->willReturn($mockInitiate);

        $response = $this->apiClientMock->initiateRefundAuto($argsV2);

        $this->assertSame(200, $response['status']);
        $this->assertSame($refundId, $response['response']['refundId']);
        $this->assertSame('ACCEPTED', $response['response']['status']);
    }

    /**
     * V2 refund initiation + mocked status poll completing.
     */
    public function testRefundInitiationV2ThenStatusMocked(): void
    {
        $refundId  = Helpers::generateUniqueId();
        $depositId = Helpers::generateUniqueId();

        $argsV2 = [
            'refundId'  => $refundId,
            'depositId' => $depositId,
            'amount'    => '50',
            'currency'  => 'UGX',
        ];

        $mockInitiate = [
            'status'   => 200,
            'response' => [
                'refundId' => $refundId,
                'status'   => 'ACCEPTED',
            ],
        ];

        $mockStatus = [
            'status'   => 200,
            'response' => [
                'refundId' => $refundId,
                'status'   => 'COMPLETED',
            ],
        ];

        // 1) initiate (v2 auto)
        $this->apiClientMock
            ->expects($this->once())
            ->method('initiateRefundAuto')
            ->willReturn($mockInitiate);

        // 2) status check (v2 auto)
        $this->apiClientMock
            ->expects($this->once())
            ->method('checkTransactionStatusAuto')
            ->with($refundId, 'refund')
            ->willReturn($mockStatus);

        $init = $this->apiClientMock->initiateRefundAuto($argsV2);
        $this->assertSame(200, $init['status']);
        $this->assertSame('ACCEPTED', $init['response']['status']);

        $status = $this->apiClientMock->checkTransactionStatusAuto($refundId, 'refund');
        $this->assertSame(200, $status['status']);
        $this->assertSame('COMPLETED', $status['response']['status']);
    }
}