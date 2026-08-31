<?php

namespace Tests\Unit\Services\Payment;

use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use DateTimeImmutable;
use Tests\Contracts\Payment\PaymentGatewayContractTestCase;
use Tests\Contracts\Payment\ProviderFault;
use Tests\Contracts\Payment\ProviderScenario;
use Tests\Fakes\Payment\PayPayFakePaymentGateway;

final class PayPayPaymentGatewayContractTest extends PaymentGatewayContractTestCase
{
    protected function gateway(CapabilitySet $capabilities): PaymentGatewayContract
    {
        return new PayPayFakePaymentGateway($capabilities, new DateTimeImmutable(self::STARTED_AT));
    }

    protected function gatewayWithFault(CapabilitySet $capabilities, ProviderFault $fault): PaymentGatewayContract
    {
        return new PayPayFakePaymentGateway($capabilities, new DateTimeImmutable(self::STARTED_AT), $fault);
    }

    protected function providerCallCount(PaymentGatewayContract $gateway, string $operation): int
    {
        self::assertInstanceOf(PayPayFakePaymentGateway::class, $gateway);

        return $gateway->callCount($operation);
    }

    protected function expectedRawStatus(ProviderScenario $scenario): string
    {
        return match ($scenario) {
            ProviderScenario::CreateSucceeded, ProviderScenario::RefundSucceeded => 'COMPLETED',
            ProviderScenario::CaptureSucceeded => 'CAPTURED',
            ProviderScenario::CancelSucceeded => 'CANCELED',
            ProviderScenario::Declined => 'FAILED',
            ProviderScenario::TimedOut => 'TIMEOUT',
            ProviderScenario::WebhookProcessing => 'AUTHORIZED',
            ProviderScenario::WebhookAlternate => 'COMPLETED',
            ProviderScenario::CaptureDeclined => 'CAPTURE_FAILED',
            ProviderScenario::CaptureTimedOut => 'CAPTURE_TIMEOUT',
            ProviderScenario::CancelDeclined => 'CANCEL_FAILED',
            ProviderScenario::CancelTimedOut => 'CANCEL_TIMEOUT',
            ProviderScenario::RefundDeclined => 'REFUND_FAILED',
            ProviderScenario::RefundTimedOut => 'REFUND_TIMEOUT',
        };
    }

    protected function signedWebhook(
        GatewayConnectionData $connection,
        string $eventId,
        string $paymentReference,
        string $rawStatus,
        string $secretMarker,
        string $correlationId,
    ): VerifyWebhookCommand {
        return new VerifyWebhookCommand(
            $connection,
            json_encode([
                'id' => $eventId,
                'type' => 'paypay.payment.completed',
                'payment' => $paymentReference,
                'status' => $rawStatus,
                'private' => $secretMarker,
            ], JSON_THROW_ON_ERROR),
            ['PayPay-Signature' => 'valid-paypay-signature'],
            $correlationId,
        );
    }

    protected function invalidWebhook(GatewayConnectionData $connection, string $correlationId): VerifyWebhookCommand
    {
        return new VerifyWebhookCommand(
            $connection,
            '{"id":"evt_invalid","type":"paypay.payment.created","payment":"pay_invalid","status":"FAILED"}',
            ['PayPay-Signature' => 'wrong-signature'],
            $correlationId,
        );
    }
}
