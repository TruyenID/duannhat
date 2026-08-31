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
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\Stripe\StripePaymentGateway;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use DateTimeImmutable;
use Tests\Support\Payment\PaymentGatewayFixtures;

/** Stripe-shaped fake for HQ admin feature tests (STRIPE_FAKE). */
final class StripeFakePaymentGateway implements PaymentGatewayContract
{
    private readonly InMemoryPaymentGateway $inner;

    public function __construct(
        private readonly ?CapabilitySet $capabilities = null,
    ) {
        $this->inner = new InMemoryPaymentGateway(
            $capabilities ?? PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    public function capabilities(GatewayConnectionData $connection): CapabilitySet
    {
        return $this->inner->capabilities($connection);
    }

    /**
     * #2938 — uỷ quyền cho adapter THẬT.
     *
     * Phân giải connection chạy TRƯỚC khi xác minh chữ ký, nên nếu fake trả lời
     * khác adapter thật thì bài test sẽ chứng minh một đường đi không tồn tại
     * trên production. Phép này không trạng thái và không cần credential —
     * `StripePaymentGateway` dựng SDK ở lượt dùng ĐẦU TIÊN (#1232), nên khởi
     * tạo ở đây không đòi `STRIPE_SECRET`.
     *
     * @param  array<string, mixed>  $payload
     */
    public function identifyConnection(array $payload): ?ConnectionLocator
    {
        return (new StripePaymentGateway)->identifyConnection($payload);
    }

    public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult
    {
        return $this->inner->preparePayment($command);
    }

    public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult
    {
        return $this->inner->retrievePayment($command);
    }

    public function capture(CapturePaymentCommand $command): GatewayPaymentResult
    {
        return $this->inner->capture($command);
    }

    public function cancel(CancelPaymentCommand $command): GatewayPaymentResult
    {
        return $this->inner->cancel($command);
    }

    public function refund(RefundPaymentCommand $command): GatewayRefundResult
    {
        return $this->inner->refund($command);
    }

    public function retrieveRefund(RetrieveRefundCommand $command): GatewayRefundResult
    {
        return $this->inner->retrieveRefund($command);
    }

    public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent
    {
        return $this->inner->verifyWebhook($command);
    }
}
