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
use Tests\Fakes\Payment\InMemoryPaymentGateway;

final class StripePaymentGatewayContractTest extends PaymentGatewayContractTestCase
{
    protected function gateway(CapabilitySet $capabilities): PaymentGatewayContract
    {
        return new InMemoryPaymentGateway($capabilities, new DateTimeImmutable(self::STARTED_AT));
    }

    protected function gatewayWithFault(CapabilitySet $capabilities, ProviderFault $fault): PaymentGatewayContract
    {
        return new InMemoryPaymentGateway($capabilities, new DateTimeImmutable(self::STARTED_AT), $fault);
    }

    protected function providerCallCount(PaymentGatewayContract $gateway, string $operation): int
    {
        self::assertInstanceOf(InMemoryPaymentGateway::class, $gateway);

        return $gateway->callCount($operation);
    }

    protected function expectedRawStatus(ProviderScenario $scenario): string
    {
        return match ($scenario) {
            ProviderScenario::CreateSucceeded, ProviderScenario::RefundSucceeded => 'succeeded',
            ProviderScenario::CaptureSucceeded => 'captured',
            ProviderScenario::CancelSucceeded => 'canceled',
            ProviderScenario::Declined => 'card_declined',
            ProviderScenario::TimedOut => 'network_timeout',
            ProviderScenario::WebhookProcessing => 'processing',
            ProviderScenario::WebhookAlternate => 'succeeded',
            ProviderScenario::CaptureDeclined => 'capture_failed',
            ProviderScenario::CaptureTimedOut => 'capture_timeout',
            ProviderScenario::CancelDeclined => 'cancel_failed',
            ProviderScenario::CancelTimedOut => 'cancel_timeout',
            ProviderScenario::RefundDeclined => 'refund_failed',
            ProviderScenario::RefundTimedOut => 'refund_timeout',
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
                'type' => 'payment_intent.succeeded',
                'payment' => $paymentReference,
                'status' => $rawStatus,
                'private' => $secretMarker,
            ], JSON_THROW_ON_ERROR),
            ['Provider-Signature' => 'valid-signature', 'Authorization' => 'header-secret'],
            $correlationId,
        );
    }

    protected function invalidWebhook(GatewayConnectionData $connection, string $correlationId): VerifyWebhookCommand
    {
        return new VerifyWebhookCommand(
            $connection,
            '{"id":"evt_invalid","object":"event","type":"payment_intent.created","data":{"object":{}}}',
            ['Stripe-Signature' => 'wrong-signature'],
            $correlationId,
        );
    }
}
