<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers;

class PayoutTest extends TestCase
{
    /** @var \PHPUnit\Framework\MockObject\MockObject&ApiClient */
    protected $apiClientMock;

    protected function setUp(): void
    {
        // Mock ONLY the methods we’ll stub; they all exist on ApiClient.
        $this->apiClientMock = $this->getMockBuilder(ApiClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'initiatePayout',             // v1
                'initiatePayoutAuto',         // v2 auto
                'checkTransactionStatusAuto', // v2 auto status
            ])
            ->getMock();
    }

    /**
     * V1 / legacy payout initiation (unchanged).
     */
    public function testPayoutInitiationMocked(): void
    {
        $samplePayoutId = Helpers::generateUniqueId();

        $mockResponse = [
            'status'   => 200,
            'response' => [
                'payoutId' => $samplePayoutId,
            ],
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiatePayout')
            ->willReturn($mockResponse);

        $recipientMsisdn       = '256783456789';
        $amount                = '200.00';
        $currency              = 'UGX';
        $correspondent         = 'MTN_MOMO_UGA';
        $statementDescription  = 'Payout for service rendered';

        $response = $this->apiClientMock->initiatePayout(
            $samplePayoutId,
            $amount,
            $currency,
            $correspondent,
            $recipientMsisdn,
            $statementDescription
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame($samplePayoutId, $response['response']['payoutId']);
    }

    /**
     * V1 / legacy: missing fields error path.
     */
    public function testPayoutMissingFields(): void
    {
        $mockResponse = [
            'status'   => 400,
            'response' => [
                'message' => 'Missing required fields.',
            ],
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiatePayout')
            ->willReturn($mockResponse);

        $recipientMsisdn       = '256783456789';
        $amount                = '200.00';
        $correspondent         = 'MTN_MOMO_UGA';
        $statementDescription  = 'Payout for service rendered';

        $response = $this->apiClientMock->initiatePayout(
            Helpers::generateUniqueId(),
            $amount,
            '', // Missing currency (forces 400 in our mock)
            $correspondent,
            $recipientMsisdn,
            $statementDescription
        );

        $this->assertSame(400, $response['status']);
        $this->assertSame('Missing required fields.', $response['response']['message']);
    }

    /**
     * V2 payout initiation via version-aware AUTO method (array payload).
     */
    public function testPayoutInitiationV2Mocked(): void
    {
        $payoutId = Helpers::generateUniqueId();

        // v2-style args: provider + customerMessage
        $argsV2 = [
            'payoutId'        => $payoutId,
            'amount'          => '200',               // string in v2
            'currency'        => 'UGX',
            'recipientMsisdn' => '256783456789',
            'provider'        => 'MTN_MOMO_UGA',
            'customerMessage' => 'Payout for service rendered',
            'metadata'        => [
                ['orderId' => 'ORD-98765'],
                ['note'    => 'unit-test'],
            ],
        ];

        $mockInitiate = [
            'status'   => 200,
            'response' => [
                'payoutId' => $payoutId,
                'status'   => 'ACCEPTED',
            ],
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiatePayoutAuto')
            ->with($this->callback(function ($payload) use ($payoutId) {
                return is_array($payload)
                    && ($payload['payoutId']        ?? null) === $payoutId
                    && ($payload['currency']        ?? null) === 'UGX'
                    && ($payload['provider']        ?? null) === 'MTN_MOMO_UGA'
                    && ($payload['recipientMsisdn'] ?? null) === '256783456789';
            }))
            ->willReturn($mockInitiate);

        $response = $this->apiClientMock->initiatePayoutAuto($argsV2);

        $this->assertSame(200, $response['status']);
        $this->assertSame($payoutId, $response['response']['payoutId']);
        $this->assertSame('ACCEPTED', $response['response']['status']);
    }

    /**
     * V2 payout initiation + mocked status poll completing.
     */
    public function testPayoutInitiationV2ThenStatusMocked(): void
    {
        $payoutId = Helpers::generateUniqueId();

        $argsV2 = [
            'payoutId'        => $payoutId,
            'amount'          => '200',
            'currency'        => 'UGX',
            'recipientMsisdn' => '256783456789',
            'provider'        => 'MTN_MOMO_UGA',
            'customerMessage' => 'Payout for service rendered',
        ];

        $mockInitiate = [
            'status'   => 200,
            'response' => [
                'payoutId' => $payoutId,
                'status'   => 'ACCEPTED',
            ],
        ];

        $mockStatus = [
            'status'   => 200,
            'response' => [
                'payoutId' => $payoutId,
                'status'   => 'COMPLETED',
            ],
        ];

        // 1) initiate
        $this->apiClientMock
            ->expects($this->once())
            ->method('initiatePayoutAuto')
            ->willReturn($mockInitiate);

        // 2) status check (v2 auto)
        $this->apiClientMock
            ->expects($this->once())
            ->method('checkTransactionStatusAuto')
            ->with($payoutId, 'payout')
            ->willReturn($mockStatus);

        $init = $this->apiClientMock->initiatePayoutAuto($argsV2);
        $this->assertSame(200, $init['status']);
        $this->assertSame('ACCEPTED', $init['response']['status']);

        $status = $this->apiClientMock->checkTransactionStatusAuto($payoutId, 'payout');
        $this->assertSame(200, $status['status']);
        $this->assertSame('COMPLETED', $status['response']['status']);
    }
}