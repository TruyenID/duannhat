<?php

namespace App\Services\Payment\ProviderEvent;

use App\Models\Brand;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentRefund;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use App\Services\Payment\Gateway\Stripe\StripeLifecycleMapper;
use App\Services\Payment\Orchestration\Commands\FinalizePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentRefundCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;
use App\Services\Payment\Settlement\Stripe\StripeSettlementRecorder;
use App\Support\Logging\MoneyOrchestrationLog;
use Illuminate\Support\Facades\Log;
use Stripe\Refund;

/** Routes inbox events through normalized orchestrator commands with legacy compatibility. */
final class ProviderEventApplicator
{
    public function __construct(
        private readonly PaymentMutationFacade $payments,
        private readonly LegacyStripeWebhookBridge $legacy,
        private readonly StripeLifecycleMapper $mapper,
        private readonly ProviderEventInboxService $inbox,
        private readonly ProviderRetrievalRecoveryService $recovery,
        private readonly StripeSettlementRecorder $settlements,
        /** #2697 — `paypay_qr_notification_unbookable` phải tới được một con người. */
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function apply(string $providerEventRecordId): string
    {
        /** @var PaymentProviderEvent $event */
        $event = PaymentProviderEvent::query()->findOrFail($providerEventRecordId);

        if ($this->inbox->isTerminal($event)) {
            return (string) ($event->outcome ?? 'ignored_terminal');
        }

        $outcome = match ($event->event_type) {
            'payment_intent.succeeded' => $this->applyPaymentIntentSucceeded($event),
            // #1125 option B — async method lifecycle (Konbini / bank transfer):
            // processing tracks the awaiting placeholder; failed/canceled fail
            // it and release the order back to payable.
            'payment_intent.processing' => $this->legacy->applyPaymentIntentProcessing($event, $this->legacy->resolvePaymentIntent($event)),
            'payment_intent.payment_failed',
            'payment_intent.canceled' => $this->legacy->applyPaymentIntentFailed($event, $this->legacy->resolvePaymentIntent($event)),
            'charge.refunded' => $this->applyChargeRefunded($event),
            // #1123 (D2) — dispute lifecycle: the bank moves money
            // unilaterally; the ledger follows (contra-revenue on withdrawal,
            // re-credit on reinstatement).
            'charge.dispute.created',
            'charge.dispute.closed',
            'charge.dispute.funds_withdrawn',
            'charge.dispute.funds_reinstated' => $this->applyStripeDispute($event),
            // Plan-050 M2 (#1155) — payout lifecycle exists ONLY for the
            // settlement sub-ledger, so the recorder owns it end-to-end. A
            // failure here throws and rides the inbox retry (there is no
            // other handler whose retries it could poison).
            'payout.paid',
            'payout.failed' => $this->settlements->applyProviderEvent($event) ?? 'ignored_unsupported',
            default => $event->provider === PaymentGatewayProviderCodeEnum::Paypay
                ? $this->applyPayPayNotification($event)
                : 'ignored_unsupported',
        };

        $this->recordSettlementEvidence($event);

        Log::channel('payment_orchestration')->info('provider_event_applied', [
            'provider_event_record_id' => $event->id,
            'event_type' => $event->event_type,
            'outcome' => $outcome,
            'route' => str_starts_with($outcome, 'orchestrator_') ? 'orchestrator' : 'legacy',
        ]);

        return $outcome;
    }

    /**
     * Plan-050 M2 (#1155) — record settlement evidence (real gateway fee via
     * balance transaction) for money-bearing Stripe events. FAIL-OPEN by
     * design: the settlement sub-ledger must never dead-letter a payment
     * event whose ledger outcome already succeeded — the daily
     * `settlements:reconcile` sweep (direction A) is the backstop for
     * anything missed here (G6). Payout events are excluded: for them the
     * recorder IS the handler and its failures must retry (see the match
     * arm above).
     */
    private function recordSettlementEvidence(PaymentProviderEvent $event): void
    {
        if ($event->provider !== PaymentGatewayProviderCodeEnum::Stripe) {
            return;
        }

        if (in_array($event->event_type, ['payout.paid', 'payout.failed'], true)) {
            return;
        }

        try {
            $outcome = $this->settlements->applyProviderEvent($event);

            if ($outcome !== null) {
                Log::channel('payment_orchestration')->info('settlement_evidence_recorded', [
                    'provider_event_record_id' => $event->id,
                    'event_type' => $event->event_type,
                    'outcome' => $outcome,
                ]);
            }
        } catch (\Throwable $e) {
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_SETTLEMENT, 'settlement_evidence_failed', [
                'provider_event_record_id' => $event->id,
                'event_type' => $event->event_type,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function applyPaymentIntentSucceeded(PaymentProviderEvent $event): string
    {
        $intent = $this->legacy->resolvePaymentIntent($event);
        $intentId = (string) $intent->id;

        $attempt = PaymentAttempt::query()
            ->where('connection_id', $event->connection_id)
            ->where('provider_object_id', $intentId)
            ->first();

        if ($attempt === null) {
            return $this->legacy->applyPaymentIntentSucceeded($event, $intent);
        }

        if ($this->isTerminalAttemptState($attempt)) {
            return 'ignored_transition';
        }

        $connection = PaymentGatewayConnection::query()->findOrFail($event->connection_id);
        $evidence = $this->mapper->mapPaymentIntent($intent, GatewayConnectionDataFactory::fromModel($connection));
        $context = $this->mutationContext($event, (int) $attempt->version);

        if ($evidence->state === PaymentAttemptStateEnum::Succeeded) {
            $this->payments->finalize(new FinalizePaymentCommand($context, (string) $attempt->id, $evidence));

            return 'orchestrator_finalized';
        }

        $this->payments->reconcile(new ReconcilePaymentCommand($context, (string) $attempt->id, $evidence));

        return 'orchestrator_reconciled';
    }

    private function applyStripeDispute(PaymentProviderEvent $event): string
    {
        // The legacy bridge owns the ledger movement (idempotent per dispute id
        // + kind); there is no orchestrator dispute state machine yet.
        return $this->legacy->applyStripeDispute($event);
    }

    private function applyChargeRefunded(PaymentProviderEvent $event): string
    {
        // The legacy bridge owns the ledger reversal (idempotent by stripe_refund_id).
        // The orchestrator pass below only reconciles the matching PaymentRefund's
        // STATE (finalizeRefund is state-only, no order_payments write) so the
        // orchestration refund state machine converges — running both cannot
        // double-refund money.
        $charge = $this->legacy->resolveCharge($event);
        $legacyOutcome = $this->legacy->applyChargeRefunded($event, $charge);

        $reconciledAny = false;
        foreach ($this->refundIdsFromEvent($event) as $refundId) {
            if ($this->reconcileOrchestratorRefund($event, $refundId)) {
                $reconciledAny = true;
            }
        }

        return $reconciledAny ? 'orchestrator_refund_reconciled' : $legacyOutcome;
    }

    /**
     * @return list<string>
     */
    private function refundIdsFromEvent(PaymentProviderEvent $event): array
    {
        $payload = is_array($event->redacted_payload) ? $event->redacted_payload : [];

        // `refund_ids` is written by attachWebhookSnapshot, but a schema drift or
        // a hand-patched inbox row could leave a scalar here. Iterating that
        // raises a warning that aborts the whole event — and the inbox would
        // then retry it five times before dead-lettering a webhook whose ledger
        // reversal already succeeded. Degrade to "no orchestrator ids" instead.
        $plural = $payload['refund_ids'] ?? [];

        $ids = [];
        foreach (is_array($plural) ? $plural : [] as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        // Backward compatibility with the historical single-id shape.
        $single = $payload['refund_id'] ?? null;
        if (is_string($single) && $single !== '') {
            $ids[] = $single;
        }

        return array_values(array_unique($ids));
    }

    private function reconcileOrchestratorRefund(PaymentProviderEvent $event, string $refundId): bool
    {
        $paymentRefund = PaymentRefund::query()
            ->where('connection_id', $event->connection_id)
            ->where('provider_refund_id', $refundId)
            ->first();

        if ($paymentRefund === null || $this->isTerminalRefundState($paymentRefund)) {
            return false;
        }

        // Money rejects a non-positive minor amount, and this runs INSIDE the
        // inbox worker: throwing here would burn all five processing attempts and
        // dead-letter an event whose ledger reversal already succeeded. A refund
        // row with no positive amount cannot produce provider evidence anyway, so
        // leave it for the reconciliation sweep instead of poisoning the event.
        $amountMinor = (int) ($paymentRefund->amount_minor ?? 0);
        if ($amountMinor <= 0) {
            Log::channel('payment_orchestration')->warning('refund_reconcile_skipped_non_positive_amount', [
                'provider_event_record_id' => $event->id,
                'payment_refund_id' => $paymentRefund->id,
                'provider_refund_id' => $refundId,
                'amount_minor' => $amountMinor,
            ]);

            return false;
        }

        $connection = PaymentGatewayConnection::query()->findOrFail($event->connection_id);
        $refundObject = Refund::constructFrom([
            'id' => $refundId,
            'object' => 'refund',
            'status' => 'succeeded',
            'amount' => $amountMinor,
            'currency' => strtolower((string) $paymentRefund->currency),
        ]);
        $evidence = $this->mapper->mapRefund($refundObject, GatewayConnectionDataFactory::fromModel($connection));
        $this->payments->reconcileRefund(new ReconcilePaymentRefundCommand(
            $this->mutationContext($event, (int) $paymentRefund->version),
            (string) $paymentRefund->id,
            $evidence,
        ));

        return true;
    }

    private function mutationContext(PaymentProviderEvent $event, ?int $expectedVersion = null): MutationContext
    {
        return new MutationContext(
            (string) $event->organization_id,
            null,
            'provider-event:'.$event->id,
            (string) $event->provider_event_id,
            $expectedVersion,
        );
    }

    /**
     * Plan-048 Gate 3 (T3.5) — PayPay notification minimum viable mapping.
     *
     * The webhook is only a TRIGGER: provider truth comes from an
     * authoritative retrieve (ProviderRetrievalRecoveryService), matching
     * PayPay's poll-recovery capability model. Payment success and refund both
     * converge through the same recovery path; an unmatched notification is
     * safely ignored — the scheduled reconciliation job remains the backstop.
     */
    private function applyPayPayNotification(PaymentProviderEvent $event): string
    {
        $reference = $event->provider_object_id;
        if ($reference === null || $reference === '') {
            return 'paypay_ignored_missing_reference';
        }

        $attempt = PaymentAttempt::query()
            ->where('connection_id', $event->connection_id)
            ->where('provider_object_id', $reference)
            ->first();

        if ($attempt !== null && ! $this->isTerminalAttemptState($attempt)) {
            return $this->recovery->recoverAttempt($attempt)
                ? 'orchestrator_paypay_attempt_recovered'
                : 'paypay_attempt_recovery_unavailable';
        }

        $refund = PaymentRefund::query()
            ->where('connection_id', $event->connection_id)
            ->where('provider_refund_id', $reference)
            ->first();

        if ($refund !== null && ! $this->isTerminalRefundState($refund)) {
            return $this->recovery->recoverRefund($refund)
                ? 'orchestrator_paypay_refund_recovered'
                : 'paypay_refund_recovery_unavailable';
        }

        // #3115 — một hàng khớp được LÀ bằng chứng sở hữu, mạnh hơn mọi phép
        // đoán theo tên tham chiếu. Truyền nó xuống thay vì để bên dưới suy lại
        // từ chuỗi `$outcome`.
        $ownedLocally = $attempt !== null || $refund !== null;

        return $this->alarmOnUnbookableNotification(
            $event,
            $reference,
            $ownedLocally
                ? 'paypay_ignored_terminal'
                : 'paypay_no_matching_attempt',
            $ownedLocally,
        );
    }

    /**
     * plan-054 M4c — both silent outcomes are a money alarm for money that is OURS.
     *
     * Either outcome means a customer may have paid with nowhere for the money
     * to land:
     *
     *   paypay_no_matching_attempt — no row to hang the payment on at all.
     *   paypay_ignored_terminal    — the attempt/refund is already terminal, so
     *                                the recovery path (the only thing that books
     *                                a PayPay payment here) will not run again.
     *
     * The outcome string is returned unchanged: it is persisted on the inbox row
     * and read by other code and tests. This adds the alarm, not a new verdict.
     *
     * #2697 — "alarm" used to mean one ERROR line and nothing else. Production
     * on 12/08: two lines, zero notifications. The log stays (DevOps matches the
     * `[...]` tag, see `MoneyFailOpenLogsAreAlertableTest`) and a notification is
     * now raised alongside it, because a customer may be standing at a counter
     * having paid into a void.
     *
     * #3115 — **phép thử sở hữu, KHÔNG phải phép thử tiền tố.** Rào này từng chỉ
     * là `isQrMerchantPaymentId()`, tức "tham chiếu có bắt đầu bằng `tempoqr-`
     * không". Tiền tố ấy sinh ra để lọc NHIỄU: merchant PayPay Live dùng chung
     * với WooCommerce `menu.betoya.jp`, relay tee mọi notification sang đây, nên
     * hàng loạt `pp_*` không phải của ta rơi vào `paypay_no_matching_attempt`
     * (đo 17/08: 32 sự kiện trong sáu giờ). Kêu vì chúng là dạy người ta tắt
     * chuông.
     *
     * Nhưng tiền tố chỉ đúng cho MỘT trong hai loại tham chiếu của chính ta:
     *
     *   - QR động customer-web → `tempoqr-<attempt>` (PayPayQrCodeClient::MPID_PREFIX)
     *   - preauth/native payment → `merchantPaymentId()` = operation id đã bỏ gạch
     *   - refund → `merchant_refund_id` = operation id, cũng là UUID trần
     *
     * Hai loại sau KHÔNG BAO GIỜ mang tiền tố, nên mọi
     * `paypay_ignored_terminal` của chúng — attempt/refund của CHÍNH TA đã
     * terminal trong khi PayPay nói COMPLETED, đúng hình dạng "tiền đã đi mà
     * không gì ghi sổ" — đi qua đây IM LẶNG. Tự tay tắt chuông ở đúng chỗ đặt
     * chuông.
     *
     * `$ownedLocally` (một hàng attempt/refund khớp `connection_id` +
     * tham chiếu) là bằng chứng sở hữu MẠNH HƠN tiền tố: đã tra ra hàng thì
     * không còn phải đoán tham chiếu này của ai. Tiền tố giữ lại cho nhánh
     * không có hàng nào khớp — đó là chỗ duy nhất còn phải đoán, và cũng là chỗ
     * nhiễu WooCommerce đổ vào. Nhiễu vì thế không tăng thêm một dòng nào.
     */
    private function alarmOnUnbookableNotification(
        PaymentProviderEvent $event,
        string $reference,
        string $outcome,
        bool $ownedLocally,
    ): string {
        if (! $ownedLocally && ! PayPayQrCodeClient::isQrMerchantPaymentId($reference)) {
            return $outcome;
        }

        MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_qr_notification_unbookable', [
            'provider_event_record_id' => $event->id,
            'provider_event_id' => $event->provider_event_id,
            'event_type' => $event->event_type,
            'connection_id' => $event->connection_id,
            'merchant_payment_id' => $reference,
            'outcome' => $outcome,
        ]);

        $this->notifyUnbookableQrNotification($event, $reference, $outcome);

        return $outcome;
    }

    /**
     * #2697 — người nhận là `org-admin` ở phạm vi TỔ CHỨC.
     *
     * Sự kiện này treo ở `payment_gateway_connections`, không ở chi nhánh nào:
     * không có `branch_id` để thu hẹp phạm vi, nên hỏi `shop-manager` sẽ hoặc
     * bắn cho quản lý mọi quán trong tổ chức, hoặc (tuỳ dữ liệu) không cho ai.
     * Cùng lựa chọn với `SettlementAlertService`, vì cùng một hình dạng: tiền
     * cấp cổng thanh toán, không phải tiền cấp quầy.
     */
    private function notifyUnbookableQrNotification(
        PaymentProviderEvent $event,
        string $reference,
        string $outcome,
    ): void {
        try {
            $connection = PaymentGatewayConnection::query()->whereKey($event->connection_id)->first();

            if ($connection === null) {
                Log::warning('paypay-qr-unbookable-alert: unknown connection', [
                    'provider_event_record_id' => $event->id,
                    'connection_id' => $event->connection_id,
                ]);

                return;
            }

            $brand = Brand::query()->whereKey($connection->brand_id)->first();

            if ($brand === null) {
                // `toRole` giải audience trong phạm vi một brand, nên thiếu
                // brand là không gửi được cho ai. Ghi lại thay vì nuốt.
                Log::warning('paypay-qr-unbookable-alert: no brand for connection', [
                    'provider_event_record_id' => $event->id,
                    'connection_id' => $connection->getKey(),
                ]);

                return;
            }

            $this->notifications->toRole(
                new NotificationRequest(
                    type: 'payment.paypay_qr_unbookable',
                    params: [
                        'merchant_payment_id' => $reference,
                        'outcome' => $outcome,
                        'event_type' => (string) $event->event_type,
                        'provider_event_id' => (string) $event->provider_event_id,
                        'connection_id' => (string) $connection->getKey(),
                    ],
                    organizationId: (string) $connection->organization_id,
                    subject: $event,
                    // Một hàng inbox = một sự kiện; retry của inbox phải cho ra
                    // đúng khoá này, không phải một thông báo mới mỗi lần.
                    idempotencyKey: "payment.paypay_qr_unbookable:{$event->id}",
                ),
                role: 'org-admin',
                scopeKey: 'organization_id',
                scopeId: (string) $connection->organization_id,
                brand: $brand,
            );
        } catch (\Throwable $e) {
            // Audience rỗng cũng rơi vào đây. Cảnh báo hỏng không được làm hỏng
            // đường xử lý sự kiện — nó vẫn phải trả `$outcome` cho inbox.
            Log::warning('paypay-qr-unbookable-alert: dispatch failed', [
                'provider_event_record_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isTerminalAttemptState(PaymentAttempt $attempt): bool
    {
        $state = $attempt->state instanceof PaymentAttemptStateEnum
            ? $attempt->state
            : PaymentAttemptStateEnum::from($attempt->state);

        return in_array($state, [
            PaymentAttemptStateEnum::Succeeded,
            PaymentAttemptStateEnum::Failed,
            PaymentAttemptStateEnum::Canceled,
            PaymentAttemptStateEnum::Expired,
        ], true);
    }

    private function isTerminalRefundState(PaymentRefund $refund): bool
    {
        $state = $refund->state instanceof PaymentRefundStateEnum
            ? $refund->state
            : PaymentRefundStateEnum::from($refund->state);

        return in_array($state, [
            PaymentRefundStateEnum::Succeeded,
            PaymentRefundStateEnum::Failed,
            PaymentRefundStateEnum::Canceled,
        ], true);
    }
}
