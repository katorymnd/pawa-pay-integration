<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Katorymnd\PawaPayIntegration\Api\ApiClient;
use Katorymnd\PawaPayIntegration\Utils\Helpers;

class InitialDepositTest extends TestCase
{
    /** @var \PHPUnit\Framework\MockObject\MockObject&ApiClient */
    protected $apiClientMock;

    protected function setUp(): void
    {
        // Mock just the real methods we use
        $this->apiClientMock = $this->getMockBuilder(ApiClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['initiateDeposit', 'initiateDepositAuto'])
            ->getMock();
    }

    /** V1 / legacy: deposit initiation */
    public function testDepositInitiationV1Mocked(): void
    {
        $sampleUuid = Helpers::generateUniqueId();

        $mockResponse = [
            'status'   => 200,
            'response' => ['depositId' => $sampleUuid],
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiateDeposit')
            ->willReturn($mockResponse);

        $amount      = '100.00';
        $mno         = 'MTN_MOMO_UGA';
        $payerMsisdn = '256783456789';
        $description = 'Payment for order';
        $currency    = 'UGX';

        $response = $this->apiClientMock->initiateDeposit(
            $sampleUuid,
            $amount,
            $currency,
            $mno,
            $payerMsisdn,
            $description
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame($sampleUuid, $response['response']['depositId']);
    }

    /** V2: deposit initiation via version-aware AUTO method (array payload) */
    public function testDepositInitiationV2Mocked(): void
    {
        $sampleUuid = Helpers::generateUniqueId();

        // Match ApiClient::initiateDepositAuto (v2 path) expected keys
        $argsV2 = [
            'depositId'          => $sampleUuid,
            'amount'             => '1000',
            'currency'           => 'UGX',
            'payerMsisdn'        => '256783456789',
            'provider'           => 'MTN_MOMO_UGA',
            'customerMessage'    => 'Payment for order',
            'clientReferenceId'  => 'INV-123456',
            // 'preAuthorisationCode' => 'optional-code',
            'metadata'           => [
                ['orderId'    => 'ORD-123456789'],
                ['customerId' => 'customer@example.com', 'isPII' => true],
            ],
        ];

        $mockResponseV2 = [
            'status'   => 200,
            'response' => [
                'depositId' => $sampleUuid,
                'status'    => 'ACCEPTED',
            ],
        ];

        $this->apiClientMock
            ->expects($this->once())
            ->method('initiateDepositAuto')
            ->with($this->callback(function ($payload) use ($sampleUuid) {
                return is_array($payload)
                    && ($payload['depositId']   ?? null) === $sampleUuid
                    && ($payload['currency']    ?? null) === 'UGX'
                    && ($payload['provider']    ?? null) === 'MTN_MOMO_UGA'
                    && ($payload['payerMsisdn'] ?? null) === '256783456789';
            }))
            ->willReturn($mockResponseV2);

        $response = $this->apiClientMock->initiateDepositAuto($argsV2);

        $this->assertSame(200, $response['status']);
        $this->assertSame($sampleUuid, $response['response']['depositId']);
        $this->assertSame('ACCEPTED', $response['response']['status']);
    }
}