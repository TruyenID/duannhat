<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentOptionRailEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\Gateway\Enums\CapabilityFact;
use App\Services\Payment\Gateway\Enums\CapabilityOperator;
use App\Services\Payment\Gateway\Enums\CapabilitySupport;
use App\Services\Payment\Gateway\Enums\CapabilityVerificationState;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Enums\PaymentWorkflow;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\ValueObjects\CapabilityCondition;
use App\Services\Payment\Gateway\ValueObjects\CapabilityEvidence;
use App\Services\Payment\Gateway\ValueObjects\CapabilityLimits;
use App\Services\Payment\Gateway\ValueObjects\CapabilityPredicate;
use App\Services\Payment\Gateway\ValueObjects\CapabilityRule;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\CapabilityVerification;
use App\Services\Payment\Gateway\ValueObjects\CurrencyCapability;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\OperationCapability;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Gateway\ValueObjects\RecoveryCapability;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use DateTimeImmutable;

final class PayPayLifecycleMapper
{
    public static function capabilitySet(PaymentGatewayEnvironmentEnum $environment): CapabilitySet
    {
        $supported = new CapabilityRule(CapabilitySupport::Supported);
        $conditionalPartialRefund = new CapabilityRule(
            CapabilitySupport::Conditional,
            new CapabilityCondition([
                new CapabilityPredicate(
                    CapabilityFact::ConnectionPartialRefundEnabled,
                    CapabilityOperator::IsTrue,
                ),
            ]),
        );

        return new CapabilitySet(
            'paypay.preauth.wallet.v1',
            1,
            PaymentGatewayProviderCodeEnum::Paypay,
            'opa_preauth_capture',
            '1.0',
            PaymentOptionRailEnum::Wallet,
            'paypay',
            ['paypay'],
            [PaymentChannelEnum::CustomerWeb],
            ['browser'],
            [new CurrencyCapability('JPY', 0)],
            $environment,
            [PaymentWorkflow::AuthorizeCapture],
            array_map(
                static fn (GatewayCapability $operation): OperationCapability => new OperationCapability($operation, $supported),
                GatewayCapability::cases(),
            ),
            new CapabilityLimits($supported, $conditionalPartialRefund, $supported, 1, 10_000_000, 30, 86400, 2_592_000),
            new RecoveryCapability(true, true, true, 'daily_reconciliation'),
            ['assume_merchant', 'store_id', 'terminal_id'],
            new DateTimeImmutable('2026-07-22T00:00:00+09:00'),
            null,
            new CapabilityVerification(CapabilityVerificationState::Verified, [
                new CapabilityEvidence(
                    'application-test:paypay-opa-preauth',
                    'account:paypay-runtime-adapter',
                    new DateTimeImmutable('2026-07-22T00:00:00+09:00'),
                    'artifact:paypay-payment-gateway',
                    'identity:plan-047-reviewer',
                    new DateTimeImmutable('2030-01-01T00:00:00+09:00'),
                ),
            ]),
        );
    }

    /**
     * plan-054 — dynamic QR (OPA Web Payment, `/v2/codes`).
     *
     * Deliberately NOT `capabilitySet()` with a different id: the QR workflow
     * supports a strictly smaller operation set. A QR payment is terminal at
     * COMPLETED (no separate capture to authorise) and no refund path is wired,
     * so declaring capture/cancel/refund here would let the policy engine
     * approve operations the adapter cannot perform.
     */
    public static function qrCapabilitySet(PaymentGatewayEnvironmentEnum $environment): CapabilitySet
    {
        $supported = new CapabilityRule(CapabilitySupport::Supported);
        $unsupported = new CapabilityRule(CapabilitySupport::Unsupported);

        $operations = [
            GatewayCapability::Create,
            GatewayCapability::RetrievePayment,
            GatewayCapability::WebhookVerification,
        ];

        return new CapabilitySet(
            'paypay.web_payment.qr.v1',
            1,
            PaymentGatewayProviderCodeEnum::Paypay,
            'opa_web_payment',
            '2.0',
            PaymentOptionRailEnum::Wallet,
            'paypay',
            ['paypay'],
            [PaymentChannelEnum::CustomerWeb],
            ['browser'],
            [new CurrencyCapability('JPY', 0)],
            $environment,
            [PaymentWorkflow::Sale],
            array_map(
                static fn (GatewayCapability $operation): OperationCapability => new OperationCapability($operation, $supported),
                $operations,
            ),
            new CapabilityLimits($unsupported, $unsupported, $unsupported, 1, 10_000_000),
            new RecoveryCapability(true, false, true, 'daily_reconciliation'),
            // Web payment has no physical store/terminal to snapshot.
            ['assume_merchant'],
            new DateTimeImmutable('2026-07-29T00:00:00+09:00'),
            null,
            new CapabilityVerification(CapabilityVerificationState::Verified, [
                new CapabilityEvidence(
                    'application-test:paypay-opa-web-payment',
                    'account:paypay-runtime-adapter',
                    new DateTimeImmutable('2026-07-29T00:00:00+09:00'),
                    'artifact:paypay-qr-code-client',
                    'identity:plan-054-reviewer',
                    new DateTimeImmutable('2030-01-01T00:00:00+09:00'),
                ),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function mapPaymentResponse(
        array $response,
        GatewayConnectionData $connection,
        string $merchantPaymentId,
        ?Money $requestedMoney = null,
        bool $useQrStateMap = false,
    ): GatewayPaymentResult {
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;

        // plan-054 T2.2 — PayPay answers HTTP 200 with the real outcome in
        // `resultInfo.code`. Reading only `data.status` laundered a provider
        // rejection into rawStatus 'UNKNOWN' → ReconciliationRequired, i.e. an
        // operator chasing money that never moved. Absent resultInfo keeps the
        // previous behaviour, so callers that never send one are unaffected.
        $resultCode = strtoupper((string) ($response['resultInfo']['code'] ?? ''));
        $providerRejected = $resultCode !== '' && $resultCode !== 'SUCCESS';

        $rawStatus = $providerRejected
            ? $resultCode
            : strtoupper((string) ($data['status'] ?? $data['paymentStatus'] ?? 'UNKNOWN'));
        $state = match (true) {
            $providerRejected => PaymentAttemptStateEnum::Failed,
            $useQrStateMap => $this->mapQrPaymentState($rawStatus),
            default => $this->mapPaymentState($rawStatus),
        };
        $paypayPaymentId = (string) ($data['paymentId'] ?? '');
        $amount = is_array($data['amount'] ?? null)
            ? (int) ($data['amount']['amount'] ?? 0)
            : (int) ($requestedMoney?->minorAmount ?? 0);
        $currency = is_array($data['amount'] ?? null)
            ? strtoupper((string) ($data['amount']['currency'] ?? 'JPY'))
            : strtoupper((string) ($requestedMoney?->currency ?? 'JPY'));

        return new GatewayPaymentResult(
            $state,
            $rawStatus,
            new ProviderObjectReference($merchantPaymentId),
            $state === PaymentAttemptStateEnum::Succeeded ? new Money($amount, $currency) : null,
            summary: new RedactedData([
                'provider_code' => 'paypay',
                'raw_status' => $rawStatus,
                'merchant_reference' => $merchantPaymentId,
                'provider_payment_reference' => $paypayPaymentId !== '' ? $paypayPaymentId : null,
                'merchant_account_reference' => $connection->merchantAccountReference,
                // #3138 — số tiền vào sổ KỂ CẢ khi lượt này không thành công.
                //
                // `Money` ở trên chỉ gắn khi `Succeeded`, đúng với ngữ nghĩa của
                // nó (tiền đã chuyển). Nhưng lúc điều tra, câu hỏi là "có giao
                // dịch ¥X nào không", và một lượt FAILED/PENDING ¥X chính là
                // thứ cần tìm — nó là dấu vết duy nhất còn lại khi tiền không
                // chuyển mà khách vẫn báo bị trừ.
                ...$this->moneyFields($data, $requestedMoney),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function mapRefundResponse(
        array $response,
        GatewayConnectionData $connection,
        string $merchantRefundId,
        ?Money $requestedMoney = null,
    ): GatewayRefundResult {
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $rawStatus = strtoupper((string) ($data['status'] ?? 'UNKNOWN'));
        $state = $this->mapRefundState($rawStatus);
        $paypayRefundId = (string) ($data['paymentId'] ?? $data['refundId'] ?? '');
        $amount = is_array($data['amount'] ?? null)
            ? (int) ($data['amount']['amount'] ?? 0)
            : (int) ($requestedMoney?->minorAmount ?? 0);
        $currency = is_array($data['amount'] ?? null)
            ? strtoupper((string) ($data['amount']['currency'] ?? 'JPY'))
            : strtoupper((string) ($requestedMoney?->currency ?? 'JPY'));

        return new GatewayRefundResult(
            $state,
            $rawStatus,
            new ProviderObjectReference($merchantRefundId),
            $state === PaymentRefundStateEnum::Succeeded ? new Money($amount, $currency) : null,
            new RedactedData([
                'provider_code' => 'paypay',
                'raw_status' => $rawStatus,
                'merchant_reference' => $merchantRefundId,
                'provider_refund_reference' => $paypayRefundId !== '' ? $paypayRefundId : null,
                'merchant_account_reference' => $connection->merchantAccountReference,
                ...$this->moneyFields($data, $requestedMoney),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function mapVerifiedWebhook(
        array $payload,
        string $payloadHash,
        GatewayConnectionData $connection,
    ): VerifiedGatewayEvent {
        $eventId = (string) ($payload['id'] ?? $payload['notification_id'] ?? $payload['notificationId'] ?? hash('sha256', $payloadHash));
        // #1110 — OPA Transaction notifications carry the merchant payment id
        // under `order_id` / `merchant_order_id` and the lifecycle under `state`.
        $paymentRef = (string) ($payload['payment'] ?? $payload['merchantPaymentId'] ?? $payload['merchant_order_id'] ?? $payload['order_id'] ?? '');
        $rawStatus = strtoupper((string) ($payload['status'] ?? $payload['state'] ?? 'UNKNOWN'));

        return new VerifiedGatewayEvent(
            $eventId,
            (string) ($payload['type'] ?? $this->opaEventType($payload)),
            new DateTimeImmutable,
            $payloadHash,
            $paymentRef !== '' ? new ProviderObjectReference($paymentRef) : null,
            null,
            new RedactedData([
                'provider_code' => 'paypay',
                'raw_status' => $rawStatus,
                'merchant_account_reference' => $connection->merchantAccountReference,
                ...$this->webhookMoneyFields($payload),
            ]),
        );
    }

    /**
     * Số tiền cho hai đường ĐỒNG BỘ (retrieve / refund).
     *
     * Chỉ trả về khoá khi CÓ nguồn thật. Không nguồn thì `$amount` ở trên rơi
     * về `0` và `$currency` rơi về `'JPY'` — hai giá trị đủ dùng để dựng một
     * `Money` mà caller sẽ bỏ đi, nhưng ghi vào SỔ thì thành một khẳng định
     * sai: "giao dịch này ¥0". Một số 0 bịa tệ hơn một ô trống, vì nó sẽ được
     * tin lúc đối soát.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|string>
     */
    private function moneyFields(array $data, ?Money $requestedMoney): array
    {
        if (is_array($data['amount'] ?? null)) {
            $fields = ['amount_minor' => (int) ($data['amount']['amount'] ?? 0)];
            $currency = strtoupper((string) ($data['amount']['currency'] ?? ''));

            if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
                $fields['currency'] = $currency;
            }

            return $fields;
        }

        if ($requestedMoney instanceof Money) {
            return [
                'amount_minor' => (int) $requestedMoney->minorAmount,
                'currency' => strtoupper((string) $requestedMoney->currency),
            ];
        }

        return [];
    }

    /**
     * Số tiền cho đường THÔNG BÁO (OPA webhook) — hình dạng payload khác hẳn.
     *
     * OPA gửi `order_amount` dạng CHUỖI (`'1340'` — đo từ payload thật của
     * #3115), còn API đồng bộ gửi `amount` dạng đối tượng. Đây là lý do hàm này
     * tách khỏi {@see moneyFields}: gộp hai hình dạng vào một chỗ đọc sẽ phải
     * đoán, và đoán ở đây nghĩa là ghi sai số tiền vào sổ điều tra.
     *
     * `currency` chỉ ghi khi payload NÓI RA. OPA hôm nay là Nhật, JPY — nhưng
     * suy ra 'JPY' từ chỗ payload im lặng là bịa, và một mã tiền tệ bịa sẽ được
     * tin y hệt một mã thật.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, int|string>
     */
    private function webhookMoneyFields(array $payload): array
    {
        $raw = $payload['order_amount']
            ?? $payload['amount_minor']
            ?? (is_array($payload['amount'] ?? null) ? ($payload['amount']['amount'] ?? null) : ($payload['amount'] ?? null));

        $fields = [];

        if (is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1)) {
            $fields['amount_minor'] = (int) $raw;
        }

        $currency = strtoupper((string) ($payload['currency']
            ?? (is_array($payload['amount'] ?? null) ? ($payload['amount']['currency'] ?? '') : '')));

        if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            $fields['currency'] = $currency;
        }

        return $fields;
    }

    public function mapPaymentState(string $rawStatus): PaymentAttemptStateEnum
    {
        return match ($rawStatus) {
            'COMPLETED', 'CAPTURED' => PaymentAttemptStateEnum::Succeeded,
            'AUTHORIZED', 'PENDING', 'CREATED' => PaymentAttemptStateEnum::ProviderPending,
            'FAILED', 'DECLINED' => PaymentAttemptStateEnum::Failed,
            'CANCELED', 'CANCELLED', 'REVERTED' => PaymentAttemptStateEnum::Canceled,
            'TIMEOUT' => PaymentAttemptStateEnum::ReconciliationRequired,
            default => PaymentAttemptStateEnum::ReconciliationRequired,
        };
    }

    /**
     * plan-054 — QR statuses.
     *
     * Differs from mapPaymentState() in exactly one place: `EXPIRED`. A dynamic
     * QR times out on its own (~5 min, PayPay-controlled and not configurable),
     * which is an ordinary terminal outcome — the customer simply did not scan.
     * mapPaymentState() has no case for it, so it falls to
     * ReconciliationRequired and would park an attempt for an operator to chase
     * for money that never moved.
     */
    public function mapQrPaymentState(string $rawStatus): PaymentAttemptStateEnum
    {
        return match ($rawStatus) {
            'EXPIRED' => PaymentAttemptStateEnum::Canceled,
            default => $this->mapPaymentState($rawStatus),
        };
    }

    public function mapRefundState(string $rawStatus): PaymentRefundStateEnum
    {
        return match ($rawStatus) {
            'COMPLETED', 'SUCCEEDED' => PaymentRefundStateEnum::Succeeded,
            'PENDING', 'PROCESSING' => PaymentRefundStateEnum::Pending,
            'FAILED' => PaymentRefundStateEnum::Failed,
            'CANCELED', 'CANCELLED' => PaymentRefundStateEnum::Canceled,
            'TIMEOUT' => PaymentRefundStateEnum::ReconciliationRequired,
            default => PaymentRefundStateEnum::ReconciliationRequired,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function opaEventType(array $payload): string
    {
        $notificationType = strtolower(trim((string) ($payload['notification_type'] ?? '')));
        if ($notificationType === '') {
            return 'paypay.payment.notification';
        }

        return 'paypay.'.str_replace(' ', '_', $notificationType).'.notification';
    }
}
