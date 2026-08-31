<?php

namespace Tests\Fakes\Payment;

use App\Services\Payment\Gateway\Commands\CancelPaymentCommand;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Exceptions\GatewayAuthenticationFailed;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\Gateway\PayPay\PayPayPaymentGateway;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use DateTimeImmutable;
use Tests\Contracts\Payment\ProviderFault;

/** PayPay sandbox fake for Plan 047 Gate 8 contract certification. */
final class PayPayFakePaymentGateway implements PaymentGatewayContract
{
    private const DEPRECATED_HOST = 'api.paypay.ne.jp';

    private readonly InMemoryPaymentGateway $inner;

    public function __construct(
        CapabilitySet $capabilities,
        DateTimeImmutable $operationStartedAt,
        ?ProviderFault $fault = null,
    ) {
        $this->inner = new InMemoryPaymentGateway($capabilities, $operationStartedAt, $fault);
    }

    public function capabilities(GatewayConnectionData $connection): CapabilitySet
    {
        return $this->inner->capabilities($connection);
    }

    /**
     * #2938 — uỷ quyền cho adapter THẬT, cùng lý do như bản Stripe: phân giải
     * connection chạy trước khi xác minh, nên một fake trả lời khác adapter
     * thật sẽ chứng nhận một đường đi không tồn tại trên production.
     *
     * @param  array<string, mixed>  $payload
     */
    public function identifyConnection(array $payload): ?ConnectionLocator
    {
        return (new PayPayPaymentGateway)->identifyConnection($payload);
    }

    public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult
    {
        $this->assertAssumeMerchantIdentity($command);

        return $this->remapPayment($this->inner->preparePayment($command));
    }

    public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult
    {
        return $this->remapPayment($this->inner->retrievePayment($command));
    }

    public function capture(CapturePaymentCommand $command): GatewayPaymentResult
    {
        return $this->remapPayment($this->inner->capture($command));
    }

    public function cancel(CancelPaymentCommand $command): GatewayPaymentResult
    {
        return $this->remapPayment($this->inner->cancel($command));
    }

    public function refund(RefundPaymentCommand $command): GatewayRefundResult
    {
        return $this->remapRefund($this->inner->refund($command));
    }

    public function retrieveRefund(RetrieveRefundCommand $command): GatewayRefundResult
    {
        return $this->remapRefund($this->inner->retrieveRefund($command));
    }

    public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent
    {
        if (str_contains($command->rawBody(), self::DEPRECATED_HOST)) {
            throw new WebhookVerificationFailed($command->correlationId);
        }

        if (($command->headers()['PayPay-Signature'] ?? null) !== 'valid-paypay-signature') {
            throw new WebhookVerificationFailed($command->correlationId);
        }

        return $this->inner->verifyWebhook(new VerifyWebhookCommand(
            $command->connection,
            $command->rawBody(),
            array_merge($command->headers(), ['Provider-Signature' => 'valid-signature']),
            $command->correlationId,
        ));
    }

    public function callCount(string $operation): int
    {
        return $this->inner->callCount($operation);
    }

    private function assertAssumeMerchantIdentity(CreatePaymentCommand $command): void
    {
        $metadata = $command->metadata->jsonSerialize();
        $assumeMerchant = $metadata['merchant_account_reference'] ?? $command->connection->merchantAccountReference;

        if ($assumeMerchant !== $command->connection->merchantAccountReference) {
            throw new GatewayAuthenticationFailed($command->request->correlationId);
        }
    }

    private function remapPayment(GatewayPaymentResult $result): GatewayPaymentResult
    {
        $rawStatus = match ($result->rawStatus) {
            'succeeded' => 'COMPLETED',
            'card_declined' => 'FAILED',
            'network_timeout' => 'TIMEOUT',
            'captured' => 'CAPTURED',
            'canceled' => 'CANCELED',
            'capture_failed' => 'CAPTURE_FAILED',
            'capture_timeout' => 'CAPTURE_TIMEOUT',
            'cancel_failed' => 'CANCEL_FAILED',
            'cancel_timeout' => 'CANCEL_TIMEOUT',
            default => $result->rawStatus,
        };

        return new GatewayPaymentResult(
            $result->state,
            $rawStatus,
            $result->payment,
            $result->processedMoney,
            $result->nextAction,
            $result->summary,
        );
    }

    private function remapRefund(GatewayRefundResult $result): GatewayRefundResult
    {
        $rawStatus = match ($result->rawStatus) {
            'succeeded' => 'COMPLETED',
            'refund_failed' => 'REFUND_FAILED',
            'refund_timeout' => 'REFUND_TIMEOUT',
            default => $result->rawStatus,
        };

        return new GatewayRefundResult(
            $result->state,
            $rawStatus,
            $result->refund,
            $result->processedMoney,
            $result->summary,
        );
    }
}
