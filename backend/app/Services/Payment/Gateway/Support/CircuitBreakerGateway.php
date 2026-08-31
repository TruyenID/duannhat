<?php

namespace App\Services\Payment\Gateway\Support;

use App\Services\Payment\Gateway\Commands\CancelPaymentCommand;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use Throwable;

/**
 * Plan-048 T7.5 / #1105 (J1) — circuit-breaker decorator over a provider
 * adapter, installed by PaymentGatewayRegistry so every registry-resolved
 * driver call feeds the breaker. Flag-gated OFF by default: with
 * `payments.circuit_breaker.enabled=false` the breaker no-ops and this class
 * is a transparent pass-through.
 *
 * Only `preparePayment` (new money) is REFUSED while the circuit is open —
 * capture/cancel/refund/retrieve are the recovery paths and must keep flowing
 * so reconciliation can drain once the provider returns; they still record
 * outcomes so a recovering provider closes the circuit. `verifyWebhook` and
 * `capabilities` never touch the provider network, so they bypass the breaker
 * entirely.
 */
final class CircuitBreakerGateway implements PaymentGatewayContract
{
    public function __construct(
        private readonly PaymentGatewayContract $inner,
        private readonly PaymentProviderCircuitBreaker $breaker,
    ) {}

    public function capabilities(GatewayConnectionData $connection): CapabilitySet
    {
        return $this->inner->capabilities($connection);
    }

    /**
     * #2938 — thuần đọc payload, không chạm mạng nhà cung cấp, nên đi thẳng
     * qua breaker như `capabilities`/`verifyWebhook`. Cho nó vào `observed()`
     * sẽ ghi nhận "thành công của provider" cho một phép chưa từng gọi
     * provider — tức làm sai chính phép đo mà breaker dựa vào.
     *
     * @param  array<string, mixed>  $payload
     */
    public function identifyConnection(array $payload): ?ConnectionLocator
    {
        return $this->inner->identifyConnection($payload);
    }

    public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult
    {
        $this->breaker->guardCreate(
            $command->connection->provider,
            $command->connection->connectionId,
            $command->request->correlationId,
        );

        return $this->observed($command->connection, fn () => $this->inner->preparePayment($command));
    }

    public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult
    {
        return $this->observed($command->connection, fn () => $this->inner->retrievePayment($command));
    }

    public function capture(CapturePaymentCommand $command): GatewayPaymentResult
    {
        return $this->observed($command->connection, fn () => $this->inner->capture($command));
    }

    public function cancel(CancelPaymentCommand $command): GatewayPaymentResult
    {
        return $this->observed($command->connection, fn () => $this->inner->cancel($command));
    }

    public function refund(RefundPaymentCommand $command): GatewayRefundResult
    {
        return $this->observed($command->connection, fn () => $this->inner->refund($command));
    }

    public function retrieveRefund(RetrieveRefundCommand $command): GatewayRefundResult
    {
        return $this->observed($command->connection, fn () => $this->inner->retrieveRefund($command));
    }

    public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent
    {
        return $this->inner->verifyWebhook($command);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function observed(GatewayConnectionData $connection, callable $call): mixed
    {
        try {
            $result = $call();
        } catch (Throwable $e) {
            $this->breaker->recordFailure($connection->provider, $connection->connectionId, $e);

            throw $e;
        }

        $this->breaker->recordSuccess($connection->provider, $connection->connectionId);

        return $result;
    }
}
