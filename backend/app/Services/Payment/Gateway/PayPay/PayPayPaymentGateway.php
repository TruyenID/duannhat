<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Services\Payment\Gateway\Commands\CancelPaymentCommand;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Exceptions\GatewayAuthenticationFailed;
use App\Services\Payment\Gateway\Exceptions\GatewayOperationFailed;
use App\Services\Payment\Gateway\Exceptions\UnsupportedPaymentOperation;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLookup;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\Money;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PayPay\OpenPaymentAPI\Client;
use PayPay\OpenPaymentAPI\Models\CapturePaymentAuthPayload;
use PayPay\OpenPaymentAPI\Models\CreatePaymentAuthPayload;
use PayPay\OpenPaymentAPI\Models\RefundPaymentPayload;
use PayPay\OpenPaymentAPI\Models\RevertAuthPayload;

/**
 * PayPay OPA PreAuth & Capture adapter via godx-jp/paypayopa-php-sdk.
 *
 * @see https://github.com/godx-jp/paypayopa-sdk-php
 */
final class PayPayPaymentGateway implements PaymentGatewayContract
{
    public function __construct(
        private readonly PayPaySdkClientFactory $clientFactory = new PayPaySdkClientFactory,
        private readonly PayPayCredentialsResolver $credentialsResolver = new PayPayCredentialsResolver,
        private readonly PayPayLifecycleMapper $mapper = new PayPayLifecycleMapper,
        private readonly PayPayMutationIdempotency $idempotency = new PayPayMutationIdempotency,
        private readonly PayPayWebhookSourceVerifier $webhookSourceVerifier = new PayPayWebhookSourceVerifier,
    ) {}

    public function capabilities(GatewayConnectionData $connection): CapabilitySet
    {
        return PayPayLifecycleMapper::capabilitySet($connection->environment);
    }

    /**
     * #2938 — kiến thức phân giải webhook của PayPay, ở TRONG adapter PayPay.
     *
     * plan-054 — **mã tham chiếu của CHÍNH TA đứng trước**, vì merchant id của
     * PayPay không phân biệt được tenant ở đây. Một merchant account PayPay
     * phục vụ cả deployment, và hàng connection mang một tham chiếu tổng hợp
     * theo từng org (chỉ mục duy nhất không có `organization_id`, nên merchant
     * id thật chỉ lưu được đúng một lần). Nên phép khớp merchant-id bên dưới
     * KHÔNG BAO GIỜ trúng với customer-web, còn lưới "một connection duy nhất"
     * trả null ngay khi có tenant thứ hai — mọi notification sẽ 400, cho cả hai
     * tenant.
     *
     * `merchant_payment_id` thì do ta sinh và duy nhất theo từng lượt, nên nó
     * chỉ đích danh connection.
     *
     * Hệ quả của cùng lý lẽ đó: `bindingMerchantAccountIds` để RỖNG. Merchant
     * id của PayPay không phải định danh chủ sở hữu, nên dùng nó làm rào chặn
     * `?connection={uuid}` sẽ là một rào an ninh giả — nó chặn nhầm chứ không
     * chứng minh gì.
     *
     * @param  array<string, mixed>  $payload
     */
    public function identifyConnection(array $payload): ?ConnectionLocator
    {
        $lookups = [];

        // Thông báo OPA Transaction mang merchant payment id của ta; nó đã từng
        // rơi vào nhiều khoá khác nhau, nên nhận đúng các dạng mà mapper đã nhận.
        $references = $this->stringValues($payload, ['merchant_order_id', 'merchantPaymentId', 'order_id', 'payment']);
        if ($references !== []) {
            $lookups[] = ConnectionLookup::providerObjectReference($references);
        }

        $merchantIds = $this->stringValues($payload, ['merchant_id', 'merchantId', 'assumeMerchant']);
        if ($merchantIds !== []) {
            $lookups[] = ConnectionLookup::merchantAccount($merchantIds);
        }

        $lookups[] = ConnectionLookup::soleActiveConnection();

        return new ConnectionLocator($lookups);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function stringValues(array $payload, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult
    {
        $this->assertCapability($command->connection, GatewayCapability::Create, $command->request->correlationId);
        $this->assertAssumeMerchantIdentity($command);

        $fingerprint = PayPayMutationIdempotency::fingerprint([
            'connection' => $command->connection->jsonSerialize(),
            'operation_id' => $command->request->operationId,
            'order_id' => $command->orderId,
            'option_id' => $command->gatewayOptionId,
            'minor_amount' => $command->money->minorAmount,
            'currency' => $command->money->currency,
            'operation' => $command->operation->value,
            'channel' => $command->channel->value,
            'policy_revision' => $command->policyRevision,
            'payment_source_sha256' => $command->paymentSourceReference() === null
                ? null
                : hash('sha256', $command->paymentSourceReference()),
            'metadata' => $command->metadata->jsonSerialize(),
        ]);

        $merchantPaymentId = $this->merchantPaymentId($command->request->operationId);

        return $this->idempotency->payment(
            $command->connection->connectionId,
            'create',
            $command->request->idempotencyKey,
            $fingerprint,
            $command->request->correlationId,
            fn (): GatewayPaymentResult => $this->createPayment($command, $merchantPaymentId),
            fn (string $merchantId): GatewayPaymentResult => $this->retrievePaymentByMerchantId(
                $command->connection,
                $merchantId,
                $command->request->correlationId,
                $command->money,
            ),
        );
    }

    public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult
    {
        $this->assertCapability($command->connection, GatewayCapability::RetrievePayment, $command->request->correlationId);

        return $this->retrievePaymentByMerchantId(
            $command->connection,
            $command->payment->value,
            $command->request->correlationId,
        );
    }

    public function capture(CapturePaymentCommand $command): GatewayPaymentResult
    {
        $this->assertCapability($command->connection, GatewayCapability::Capture, $command->request->correlationId);

        $client = $this->client($command->connection);
        $merchantPaymentId = $command->payment->value;
        $payload = (new CapturePaymentAuthPayload)
            ->setMerchantPaymentId($merchantPaymentId)
            ->setAmount([
                'amount' => $command->money->minorAmount,
                'currency' => $command->money->currency,
            ])
            ->setMerchantCaptureId($command->request->operationId)
            ->setRequestedAt()
            ->setOrderDescription('Tempo capture');

        $response = PayPaySdkCallGuard::invoke(
            fn (): array => $client->payment->capturePaymentAuth($payload),
            $command->request->correlationId,
        );

        return $this->mapper->mapPaymentResponse(
            $response,
            $command->connection,
            $merchantPaymentId,
            $command->money,
        );
    }

    public function cancel(CancelPaymentCommand $command): GatewayPaymentResult
    {
        $this->assertCapability($command->connection, GatewayCapability::Cancel, $command->request->correlationId);

        $client = $this->client($command->connection);
        $merchantPaymentId = $command->payment->value;
        $paypayPaymentId = $this->resolvePayPayPaymentId(
            $command->connection,
            $merchantPaymentId,
            $command->request->correlationId,
        );
        $payload = (new RevertAuthPayload)
            ->setMerchantRevertId($command->request->operationId)
            ->setPaymentId($paypayPaymentId)
            ->setRequestedAt()
            ->setReason('merchant_cancel');

        $response = PayPaySdkCallGuard::invoke(
            fn (): array => $client->payment->revertAuth($payload),
            $command->request->correlationId,
        );

        return $this->mapper->mapPaymentResponse(
            $response,
            $command->connection,
            $merchantPaymentId,
        );
    }

    public function refund(RefundPaymentCommand $command): GatewayRefundResult
    {
        $this->assertCapability($command->connection, GatewayCapability::Refund, $command->request->correlationId);

        $fingerprint = PayPayMutationIdempotency::fingerprint([
            'connection' => $command->connection->jsonSerialize(),
            'operation_id' => $command->request->operationId,
            'payment' => $command->payment->value,
            'minor_amount' => $command->money->minorAmount,
            'currency' => $command->money->currency,
            'metadata' => $command->metadata->jsonSerialize(),
        ]);

        return $this->idempotency->refund(
            $command->connection->connectionId,
            'refund',
            $command->request->idempotencyKey,
            $fingerprint,
            $command->request->correlationId,
            fn (): GatewayRefundResult => $this->createRefund($command),
            fn (string $merchantRefundId): GatewayRefundResult => $this->retrieveRefundByMerchantId(
                $command->connection,
                $merchantRefundId,
                $command->request->correlationId,
                $command->money,
            ),
        );
    }

    public function retrieveRefund(RetrieveRefundCommand $command): GatewayRefundResult
    {
        $this->assertCapability($command->connection, GatewayCapability::RetrieveRefund, $command->request->correlationId);

        return $this->retrieveRefundByMerchantId(
            $command->connection,
            $command->refund->value,
            $command->request->correlationId,
        );
    }

    public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent
    {
        $rawBody = $command->rawBody();

        if (str_contains($rawBody, PayPayConstants::DEPRECATED_HOST)) {
            throw new WebhookVerificationFailed($command->correlationId);
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

        $headers = $command->headers();
        $signature = $headers['PayPay-Signature'] ?? $headers['Authorization'] ?? null;
        $credentials = $this->credentialsResolver->forConnection($command->connection);
        $isOpaPayload = is_string($payload['notification_type'] ?? null)
            && trim((string) $payload['notification_type']) !== '';

        if ($this->verifyOptionalHmac($credentials, $rawBody, $signature, $command->correlationId)) {
            // Optional simulation path when PAYPAY_WEBHOOK_SECRET is configured.
        } elseif ($isOpaPayload) {
            $this->verifyOpaWebhookSource($command);
        } elseif ($command->connection->environment === PaymentGatewayEnvironmentEnum::Live) {
            throw new WebhookVerificationFailed($command->correlationId);
        } elseif (! is_string($signature) || trim($signature) === '') {
            throw new WebhookVerificationFailed($command->correlationId);
        } else {
            Log::channel('payment_orchestration')->warning('paypay_webhook_unverified_accept', [
                'connection_id' => $command->connection->connectionId,
                'environment' => $command->connection->environment->value,
                'correlation_id' => $command->correlationId,
            ]);
        }

        return $this->mapper->mapVerifiedWebhook(
            $payload,
            hash('sha256', $rawBody),
            $command->connection,
        );
    }

    private function verifyOptionalHmac(
        PayPayCredentials $credentials,
        string $rawBody,
        mixed $signature,
        string $correlationId,
    ): bool {
        if ($credentials->webhookSecret === null || $credentials->webhookSecret === '') {
            return false;
        }

        if (! is_string($signature) || trim($signature) === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $credentials->webhookSecret);
        if (! hash_equals($expected, $signature) && ! str_contains($signature, $expected)) {
            throw new WebhookVerificationFailed($correlationId);
        }

        return true;
    }

    private function verifyOpaWebhookSource(VerifyWebhookCommand $command): void
    {
        if ($command->connection->environment !== PaymentGatewayEnvironmentEnum::Live) {
            Log::channel('payment_orchestration')->warning('paypay_webhook_unverified_accept', [
                'connection_id' => $command->connection->connectionId,
                'environment' => $command->connection->environment->value,
                'correlation_id' => $command->correlationId,
            ]);

            return;
        }

        if (! $this->webhookSourceVerifier->isAllowed($command->clientIp(), $command->connection->environment)) {
            throw new WebhookVerificationFailed($command->correlationId);
        }
    }

    private function createPayment(CreatePaymentCommand $command, string $merchantPaymentId): GatewayPaymentResult
    {
        $metadata = $command->metadata->jsonSerialize();
        $userAuthorizationId = $command->paymentSourceReference()
            ?? (is_string($metadata['user_authorization_id'] ?? null) ? $metadata['user_authorization_id'] : null);

        if ($userAuthorizationId === null || trim($userAuthorizationId) === '') {
            throw new InvalidArgumentException('PayPay prepare requires user_authorization_id or payment source reference.');
        }

        $client = $this->client($command->connection);
        $payload = (new CreatePaymentAuthPayload)
            ->setMerchantPaymentId($merchantPaymentId)
            ->setUserAuthorizationId($userAuthorizationId)
            ->setAmount([
                'amount' => $command->money->minorAmount,
                'currency' => $command->money->currency,
            ])
            ->setRequestedAt()
            ->setExpiresAt(new \DateTime('+1 hour'));

        $response = PayPaySdkCallGuard::invoke(
            fn (): array => $client->payment->createPaymentAuth($payload),
            $command->request->correlationId,
        );

        $authorized = $this->mapper->mapPaymentResponse(
            $response,
            $command->connection,
            $merchantPaymentId,
            $command->money,
        );

        if ($command->operation !== PaymentAttemptOperationEnum::Sale) {
            return $authorized;
        }

        return $this->capture(new CapturePaymentCommand(
            $command->connection,
            $command->request,
            $authorized->payment ?? throw new InvalidArgumentException('PayPay authorize did not return a payment identity.'),
            $command->money,
            $command->metadata,
        ));
    }

    private function createRefund(RefundPaymentCommand $command): GatewayRefundResult
    {
        $client = $this->client($command->connection);
        $merchantRefundId = $command->request->operationId;
        $paypayPaymentId = $this->resolvePayPayPaymentId(
            $command->connection,
            $command->payment->value,
            $command->request->correlationId,
        );
        $payload = (new RefundPaymentPayload)
            ->setMerchantRefundId($merchantRefundId)
            ->setPaymentId($paypayPaymentId)
            ->setAmount([
                'amount' => $command->money->minorAmount,
                'currency' => $command->money->currency,
            ])
            ->setRequestedAt()
            ->setReason('merchant_refund');

        $response = PayPaySdkCallGuard::invoke(
            fn (): array => $client->refund->refundPayment($payload),
            $command->request->correlationId,
        );

        return $this->mapper->mapRefundResponse(
            $response,
            $command->connection,
            $merchantRefundId,
            $command->money,
        );
    }

    /**
     * plan-054 T2.6 — the endpoint depends on how the payment was created.
     *
     * `Payment::getPaymentDetails` reads `/v2/payments/{mpid}`;
     * `Code::getPaymentDetails` reads `/v2/codes/payments/{mpid}`. The SDK offers
     * no parameter to choose (`endpointByPaymentType` special-cases only
     * 'pending'), and asking the wrong one 404s — which the SDK throws on, the
     * call guard re-raises, and the provider-event job dead-letters after five
     * retries. Dispatching on the merchant payment id covers all three call
     * sites here, including the two that hold only a bare id.
     */
    private function retrievePaymentByMerchantId(
        GatewayConnectionData $connection,
        string $merchantPaymentId,
        string $correlationId,
        ?Money $requestedMoney = null,
    ): GatewayPaymentResult {
        $client = $this->client($connection);
        $isQrPayment = PayPayQrCodeClient::isQrMerchantPaymentId($merchantPaymentId);

        $response = PayPaySdkCallGuard::invoke(
            fn (): array => $isQrPayment
                ? $client->code->getPaymentDetails($merchantPaymentId)
                : $client->payment->getPaymentDetails($merchantPaymentId),
            $correlationId,
        );

        return $this->mapper->mapPaymentResponse(
            $response,
            $connection,
            $merchantPaymentId,
            $requestedMoney,
            // EXPIRED is an ordinary terminal outcome for a QR (the customer did
            // not scan in time); the preauth map has no case for it and would
            // park the attempt as ReconciliationRequired.
            useQrStateMap: $isQrPayment,
        );
    }

    private function retrieveRefundByMerchantId(
        GatewayConnectionData $connection,
        string $merchantRefundId,
        string $correlationId,
        ?Money $requestedMoney = null,
    ): GatewayRefundResult {
        $client = $this->client($connection);
        $response = PayPaySdkCallGuard::invoke(
            fn (): array => $client->refund->getRefundDetails($merchantRefundId),
            $correlationId,
        );

        return $this->mapper->mapRefundResponse(
            $response,
            $connection,
            $merchantRefundId,
            $requestedMoney,
        );
    }

    private function client(GatewayConnectionData $connection): Client
    {
        $credentials = $this->credentialsResolver->forConnection($connection);
        if (! $credentials->isConfigured()) {
            throw new GatewayAuthenticationFailed('paypay:client:unconfigured');
        }

        return $this->clientFactory->forConnection($connection, $credentials);
    }

    private function merchantPaymentId(string $operationId): string
    {
        return substr(str_replace('-', '', $operationId), 0, 64);
    }

    private function resolvePayPayPaymentId(
        GatewayConnectionData $connection,
        string $merchantPaymentId,
        string $correlationId,
    ): string {
        $retrieved = $this->retrievePaymentByMerchantId($connection, $merchantPaymentId, $correlationId);
        $summary = $retrieved->summary->jsonSerialize();
        $paypayPaymentId = $summary['provider_payment_reference'] ?? null;

        if (is_string($paypayPaymentId) && $paypayPaymentId !== '') {
            return $paypayPaymentId;
        }

        // plan-054 T2.7 — this used to fall back to the merchant payment id.
        // Those are different identifiers: PayPay's refund and cancel APIs want
        // the provider-minted paymentId, so the fallback fired a mutation at an
        // id that does not exist on their side. Failing loudly is the only safe
        // option on a money path.
        throw new GatewayOperationFailed($correlationId, 'PAYPAY_PAYMENT_ID_UNRESOLVED');
    }

    private function assertAssumeMerchantIdentity(CreatePaymentCommand $command): void
    {
        $metadata = $command->metadata->jsonSerialize();
        $assumeMerchant = $metadata['merchant_account_reference'] ?? $command->connection->merchantAccountReference;

        if ($assumeMerchant !== $command->connection->merchantAccountReference) {
            throw new GatewayAuthenticationFailed($command->request->correlationId);
        }
    }

    private function assertCapability(
        GatewayConnectionData $connection,
        GatewayCapability $capability,
        string $correlationId,
    ): void {
        $capabilities = $this->capabilities($connection);

        if ($capabilities->supports($capability, new DateTimeImmutable)) {
            return;
        }

        throw new UnsupportedPaymentOperation(
            $capability,
            $capabilities->id,
            $capabilities->revision,
            $capabilities->apiVersion,
            $correlationId,
        );
    }
}
