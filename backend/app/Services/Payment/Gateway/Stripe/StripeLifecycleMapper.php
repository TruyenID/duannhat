<?php

namespace App\Services\Payment\Gateway\Stripe;

use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\GatewayNextAction;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use DateTimeImmutable;
use Stripe\Charge;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;

/** Normalizes Stripe PaymentIntent, Refund, and webhook events to gateway results. */
final class StripeLifecycleMapper
{
    public function mapPaymentIntent(PaymentIntent $intent, GatewayConnectionData $connection): GatewayPaymentResult
    {
        $currency = strtoupper((string) $intent->currency);
        $rawStatus = $this->normalizeRawStatus($intent->status ?? null, 'unknown');
        $state = $this->mapIntentState($rawStatus, $intent);
        $money = new Money((int) $intent->amount, $currency);
        $nextAction = $state === PaymentAttemptStateEnum::ActionRequired
            ? $this->mapNextAction($intent)
            : null;

        return new GatewayPaymentResult(
            $state,
            $rawStatus,
            new ProviderObjectReference((string) $intent->id),
            $this->processedMoney($state, $intent, $money),
            $nextAction,
            $this->paymentSummary($intent, $connection, $rawStatus),
        );
    }

    public function mapRefund(Refund $refund, GatewayConnectionData $connection): GatewayRefundResult
    {
        $currency = strtoupper((string) ($refund->currency ?? 'JPY'));
        $rawStatus = $this->normalizeRawStatus($refund->status ?? null, 'unknown');
        $state = $this->mapRefundState($rawStatus);
        $money = new Money((int) $refund->amount, $currency);

        return new GatewayRefundResult(
            $state,
            $rawStatus,
            new ProviderObjectReference((string) $refund->id),
            $state === PaymentRefundStateEnum::Succeeded ? $money : null,
            $this->refundSummary($refund, $connection, $rawStatus),
        );
    }

    public function mapVerifiedEvent(Event $event, string $payloadHash, GatewayConnectionData $connection): VerifiedGatewayEvent
    {
        $object = $event->data->object ?? null;
        $payment = null;
        $refund = null;
        $rawStatus = 'unknown';

        if ($object instanceof PaymentIntent) {
            $payment = new ProviderObjectReference((string) $object->id);
            $rawStatus = $this->normalizeRawStatus($object->status ?? null, 'provider_pending');
        } elseif ($object instanceof Charge) {
            $paymentIntentId = (string) ($object->payment_intent ?? '');
            $payment = $paymentIntentId !== ''
                ? new ProviderObjectReference($paymentIntentId)
                : new ProviderObjectReference((string) $object->id);
            $rawStatus = $this->normalizeRawStatus($object->status ?? null, 'succeeded');
        } elseif ($object instanceof Refund) {
            $refund = new ProviderObjectReference((string) $object->id);
            $rawStatus = $this->normalizeRawStatus($object->status ?? null, 'pending');
            $paymentIntentId = (string) ($object->payment_intent ?? '');
            if ($paymentIntentId !== '') {
                $payment = new ProviderObjectReference($paymentIntentId);
            }
        }

        return new VerifiedGatewayEvent(
            (string) $event->id,
            (string) $event->type,
            (new DateTimeImmutable)->setTimestamp((int) $event->created),
            $payloadHash,
            $payment,
            $refund,
            new RedactedData(array_merge(
                StripeConnectScope::summaryFields($connection),
                [
                    'event_type' => (string) $event->type,
                    'raw_status' => $rawStatus,
                    'provider_code' => 'stripe',
                    // Đối tượng của event là PaymentIntent, Charge hay Refund
                    // tuỳ loại; cả ba đều mang `amount` + `currency`. Loại nào
                    // không mang thì không ghi gì — xem moneyFields().
                    ...$this->moneyFields($object->amount ?? null, $object->currency ?? null),
                ],
            )),
        );
    }

    public function mapIntentState(string $rawStatus, ?PaymentIntent $intent = null): PaymentAttemptStateEnum
    {
        if ($rawStatus === 'requires_payment_method' && $this->intentHasTerminalError($intent)) {
            return PaymentAttemptStateEnum::Failed;
        }

        return match ($rawStatus) {
            'succeeded' => PaymentAttemptStateEnum::Succeeded,
            'processing' => PaymentAttemptStateEnum::Processing,
            'canceled' => PaymentAttemptStateEnum::Canceled,
            'requires_action' => PaymentAttemptStateEnum::ActionRequired,
            'requires_payment_method', 'requires_confirmation', 'requires_capture' => PaymentAttemptStateEnum::ProviderPending,
            default => PaymentAttemptStateEnum::ReconciliationRequired,
        };
    }

    public function mapRefundState(string $rawStatus): PaymentRefundStateEnum
    {
        return match ($rawStatus) {
            'succeeded' => PaymentRefundStateEnum::Succeeded,
            'pending' => PaymentRefundStateEnum::Pending,
            'failed' => PaymentRefundStateEnum::Failed,
            'canceled' => PaymentRefundStateEnum::Canceled,
            default => PaymentRefundStateEnum::ReconciliationRequired,
        };
    }

    private function mapNextAction(PaymentIntent $intent): GatewayNextAction
    {
        $nextAction = $intent->next_action ?? null;
        if (! is_object($nextAction)) {
            return GatewayNextAction::wait(5);
        }

        $type = (string) ($nextAction->type ?? '');

        return match ($type) {
            'redirect_to_url' => $this->redirectNextAction($nextAction),
            'use_stripe_sdk', 'alipay_handle_redirect' => GatewayNextAction::providerSdk([
                'client_secret' => (string) ($intent->client_secret ?? ''),
                'next_action_type' => $type,
            ]),
            'display_bank_transfer_instructions' => GatewayNextAction::displayInstructions('STRIPE_BANK_TRANSFER'),
            'oxxo_display_details' => GatewayNextAction::displayInstructions('STRIPE_OXXO'),
            default => GatewayNextAction::wait(5),
        };
    }

    private function redirectNextAction(object $nextAction): GatewayNextAction
    {
        $url = (string) ($nextAction->redirect_to_url->url ?? '');

        if ($url === '') {
            return GatewayNextAction::wait(5);
        }

        return GatewayNextAction::redirect($url);
    }

    private function processedMoney(PaymentAttemptStateEnum $state, PaymentIntent $intent, Money $money): ?Money
    {
        if ($state !== PaymentAttemptStateEnum::Succeeded) {
            return null;
        }

        $received = (int) ($intent->amount_received ?? $intent->amount ?? $money->minorAmount);

        return new Money($received, $money->currency);
    }

    /** @return array<string, bool|int|string> */
    private function paymentSummary(PaymentIntent $intent, GatewayConnectionData $connection, string $rawStatus): RedactedData
    {
        return new RedactedData(array_merge(
            StripeConnectScope::summaryFields($connection),
            [
                'raw_status' => $rawStatus,
                'provider_code' => 'stripe',
                'provider_idempotency_key' => $this->providerIdempotencyKey($intent),
                'capture_method' => (string) ($intent->capture_method ?? 'automatic'),
                // #3138 — `amount` (đã uỷ quyền) chứ không phải `amount_received`.
                //
                // Hai số này khác nhau ở đúng ca cần điều tra: uỷ quyền ¥1.340
                // rồi capture 0 thì `amount_received` = 0, và một sổ ghi ¥0 sẽ
                // trả lời SAI cho câu "có giao dịch ¥1.340 nào không" — câu duy
                // nhất người ta hỏi khi khách nói đã bị trừ tiền.
                ...$this->moneyFields($intent->amount ?? null, $intent->currency ?? null),
            ],
        ));
    }

    /** @return array<string, bool|int|string> */
    private function refundSummary(Refund $refund, GatewayConnectionData $connection, string $rawStatus): RedactedData
    {
        return new RedactedData(array_merge(
            StripeConnectScope::summaryFields($connection),
            [
                'raw_status' => $rawStatus,
                'provider_code' => 'stripe',
                'provider_idempotency_key' => $this->providerIdempotencyKey($refund),
                ...$this->moneyFields($refund->amount ?? null, $refund->currency ?? null),
            ],
        ));
    }

    /**
     * #3138 — chỉ trả về khoá khi cổng THẬT SỰ nói ra số tiền.
     *
     * Payload Stripe mà thiếu `amount` là chuyện có thật (một số loại event chỉ
     * mang định danh), và điền `0` vào chỗ đó là ghi một khẳng định sai về tiền
     * vào đúng cái sổ người ta sẽ tin lúc đối soát. Ô trống nói "không biết"; số
     * 0 nói "không có đồng nào" — hai câu khác nhau.
     *
     * Stripe trả `currency` viết THƯỜNG (`jpy`); chuẩn hoá tại đây để sổ chỉ có
     * MỘT hình dạng cho một mã tiền tệ.
     *
     * @return array<string, int|string>
     */
    private function moneyFields(mixed $amount, mixed $currency): array
    {
        $fields = [];

        if (is_int($amount) || (is_string($amount) && preg_match('/^-?\d+$/', $amount) === 1)) {
            $fields['amount_minor'] = (int) $amount;
        }

        $code = strtoupper((string) ($currency ?? ''));

        if (preg_match('/^[A-Z]{3}$/', $code) === 1) {
            $fields['currency'] = $code;
        }

        return $fields;
    }

    private function providerIdempotencyKey(PaymentIntent|Refund $object): string
    {
        $metadata = $object->metadata ?? null;
        $key = null;

        if (is_array($metadata)) {
            $key = $metadata['idempotency_key'] ?? null;
        } elseif (is_object($metadata)) {
            $key = $metadata->idempotency_key ?? null;
        }

        return is_string($key) && $key !== '' ? $key : 'unknown';
    }

    private function intentHasTerminalError(?PaymentIntent $intent): bool
    {
        if ($intent === null) {
            return false;
        }

        $error = $intent->last_payment_error ?? null;

        return is_object($error) && (string) ($error->code ?? '') !== '';
    }

    private function normalizeRawStatus(mixed $status, string $fallback): string
    {
        if (is_string($status) && $status !== '') {
            return $status;
        }

        return $fallback;
    }
}
