<?php

namespace App\Services\Payment\ProviderEvent;

use App\Models\OrderPayment;
use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Facades\Log;
use Stripe\Charge;
use Stripe\PaymentIntent;

/** Legacy customer-web Stripe webhook side effects until Gate 4 full orchestrator cutover. */
final class LegacyStripeWebhookBridge
{
    public function __construct(
        private readonly StripePaymentService $stripe,
        private readonly OrderPaymentService $orderPayments,
    ) {}

    public function applyPaymentIntentSucceeded(PaymentProviderEvent $event, PaymentIntent $intent): string
    {
        $intentId = (string) $intent->id;

        if ($this->isStalePaymentIntentEvent($intentId)) {
            Log::channel('payment_orchestration')->info('provider_event_ignored_stale', [
                'provider_event_record_id' => $event->id,
                'payment_intent_id' => $intentId,
            ]);

            return 'ignored_stale';
        }

        $order = $this->stripe->markOrderPaidFromIntent($intent);

        if ($order === null) {
            $this->reportUnattributedIntent($intent, (string) $event->event_type);

            return 'ignored_no_order';
        }

        return 'applied';
    }

    /**
     * #1125 option B — `payment_intent.processing`: Konbini voucher printed /
     * bank transfer initiated. Track the awaiting-async placeholder so the
     * order shows an in-flight payment instead of looking untouched.
     */
    public function applyPaymentIntentProcessing(PaymentProviderEvent $event, PaymentIntent $intent): string
    {
        if ($this->isStalePaymentIntentEvent((string) $intent->id)) {
            return 'ignored_stale';
        }

        $order = $this->stripe->trackAsyncPendingFromIntent($intent);

        if ($order === null) {
            $this->reportUnattributedIntent($intent, (string) $event->event_type);

            return 'ignored_no_order';
        }

        return 'applied';
    }

    /**
     * #1125 option B — `payment_intent.payment_failed` / `payment_intent.canceled`:
     * the async attempt died (voucher expired, transfer bounced, intent
     * canceled). Fail the pending row and release the order back to payable.
     */
    public function applyPaymentIntentFailed(PaymentProviderEvent $event, PaymentIntent $intent): string
    {
        return $this->orderPayments->failAsyncPendingPayment(
            (string) $intent->id,
            (string) $event->event_type,
        );
    }

    public function applyChargeRefunded(PaymentProviderEvent $event, Charge $charge): string
    {
        foreach ($this->stripe->extractRefunds($charge) as $refund) {
            $this->orderPayments->syncStripeRefund(
                $refund['payment_intent'],
                $refund['stripe_refund_id'],
                $refund['amount'],
            );
        }

        return 'applied';
    }

    /**
     * #1123 (D2) — route a dispute lifecycle event onto the ledger. The
     * dispute object rides the inbox row as `dispute_snapshot` (attached at
     * intake); with no snapshot there is nothing safe to apply — log and
     * drain rather than guessing amounts.
     */
    public function applyStripeDispute(PaymentProviderEvent $event): string
    {
        $payload = is_array($event->redacted_payload) ? $event->redacted_payload : [];
        $snapshot = $payload['dispute_snapshot'] ?? null;

        if (! is_array($snapshot)) {
            Log::channel('payment_orchestration')->warning('stripe_dispute_missing_snapshot', [
                'provider_event_record_id' => $event->id,
                'event_type' => $event->event_type,
            ]);

            return 'ignored_missing_snapshot';
        }

        $dispute = $this->stripe->extractDisputeFromSnapshot($snapshot);

        if ($dispute === null) {
            Log::channel('payment_orchestration')->warning('stripe_dispute_unresolvable_snapshot', [
                'provider_event_record_id' => $event->id,
                'event_type' => $event->event_type,
            ]);

            return 'ignored_missing_snapshot';
        }

        $result = $this->orderPayments->syncStripeDispute(
            (string) $event->event_type,
            $dispute['payment_intent'],
            $dispute['dispute_id'],
            $dispute['amount'],
            $dispute['status'] !== '' ? $dispute['status'] : null,
        );

        return $result === null ? 'ignored_no_effect' : 'applied';
    }

    public function resolvePaymentIntent(PaymentProviderEvent $event): PaymentIntent
    {
        $payload = is_array($event->redacted_payload) ? $event->redacted_payload : [];
        $snapshot = $payload['intent_snapshot'] ?? null;

        if (is_array($snapshot)) {
            return PaymentIntent::constructFrom($snapshot);
        }

        $intentId = (string) ($event->provider_object_id ?? '');

        if ($intentId === '') {
            throw new \RuntimeException('Payment intent snapshot is missing.');
        }

        return $this->stripe->retrieveIntent($intentId);
    }

    public function resolveCharge(PaymentProviderEvent $event): Charge
    {
        $payload = is_array($event->redacted_payload) ? $event->redacted_payload : [];
        $snapshot = $payload['charge_snapshot'] ?? null;

        if (is_array($snapshot)) {
            return Charge::constructFrom($snapshot);
        }

        $chargeId = (string) ($event->provider_object_id ?? '');

        if ($chargeId === '') {
            throw new \RuntimeException('Charge snapshot is missing.');
        }

        return $this->stripe->retrieveCharge($chargeId);
    }

    /**
     * #2851 — một PaymentIntent không quy được về đơn nào có HAI nghĩa rất khác
     * nhau, và gộp chúng vào một dòng `Log::info` là cách cả hai cùng chìm.
     *
     * **Không phải của chúng ta.** Tài khoản Stripe này dùng CHUNG với trang
     * đặt món online riêng của quán (WooCommerce + PaymentPlugins), và Stripe
     * gửi mọi sự kiện của tài khoản tới mọi endpoint đã đăng ký. Đo 2026-08-14:
     * 47 sự kiện không khớp đơn trong khi Tempo chỉ tạo 14 PaymentIntent; tra
     * ngược ba cái đầu qua Stripe API thì cả ba mang `partner: PaymentPlugins`,
     * `gateway_id: stripe_applepay` và mô tả "… を ベトヤ ネット注文 に注文する".
     * Đây là nhiễu, không phải tiền thất lạc — nhưng 47 dòng INFO mỗi ngày thì
     * làm người đọc log tin rằng có 47 đơn hỏng.
     *
     * **Của chúng ta nhưng mất liên kết.** Đây là tiền đã trừ của khách mà
     * không quy được về đơn. Nó phải KÊU.
     *
     * Phân biệt bằng `metadata.order_id` — luật sống ở `StripeIntentOrigin`,
     * dùng chung với tầng ingest sổ đối soát (#2864). Ở ĐÂY chỉ có hai lối ra
     * nên `Unknown` đi cùng `ForeignAccount`: một intent của ta luôn mang
     * `metadata.order_id`, nên thiếu dấu ⇒ không đủ cớ để kêu.
     */
    private function reportUnattributedIntent(PaymentIntent $intent, string $eventType): void
    {
        $intentId = (string) $intent->id;
        $orderId = (string) (data_get($intent, 'metadata.order_id') ?? '');

        if (StripeIntentOrigin::fromOrderIdMetadata($orderId) === StripeIntentOrigin::Tempo) {
            Log::channel('payment_orchestration')->warning('stripe_intent_unattributed', [
                'payment_intent' => $intentId,
                'event_type' => $eventType,
                'metadata_order_id' => $orderId,
            ]);

            return;
        }

        Log::channel('payment_orchestration')->debug('stripe_event_foreign_account', [
            'payment_intent' => $intentId,
            'event_type' => $eventType,
            'metadata_order_id' => $orderId !== '' ? $orderId : null,
        ]);
    }

    private function isStalePaymentIntentEvent(string $paymentIntentId): bool
    {
        // #1125 option B — a PENDING row is the awaiting-async placeholder the
        // succeeded event exists to settle; only a non-pending row means the
        // intent's money story is already told.
        return OrderPayment::query()
            ->where('reference_no', $paymentIntentId)
            ->where('status', '!=', PaymentStatusEnum::Pending->value)
            ->exists();
    }
}
