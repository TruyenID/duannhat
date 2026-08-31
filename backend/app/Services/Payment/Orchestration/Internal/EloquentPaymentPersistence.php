<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\OrderPayment;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentPolicyRevision;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentRefund;
use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentProviderEventStateEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Payment\Gateway\Exceptions\WebhookPayloadConflict;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Orchestration\Commands\AttachCustomerWebPrepareReferenceCommand;
use App\Services\Payment\Orchestration\Commands\FinalizePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ProcessProviderEventCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentRefundCommand;
use App\Services\Payment\Orchestration\Commands\RecordResolvedPaymentTenderCommand;
use App\Services\Payment\Orchestration\Commands\ReserveVerifiedPaymentAttemptCommand;
use App\Services\Payment\Orchestration\Commands\ReserveVerifiedRefundCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentPersistencePort;
use App\Services\Payment\Orchestration\Contracts\PaymentQueryPort;
use App\Services\Payment\Orchestration\Results\PaymentAttemptOutcome;
use App\Services\Payment\Orchestration\Results\PaymentFinalizeResult;
use App\Services\Payment\Orchestration\Results\PaymentPrepareResult;
use App\Services\Payment\Orchestration\Results\PaymentRefundResult;
use App\Services\Payment\Orchestration\Results\PrepareReferenceAttachmentResult;
use App\Services\Payment\Orchestration\Results\ProviderEventResult;
use App\Services\Payment\Orchestration\Support\PaymentOrchestrationLogContext;
use App\Services\Payment\Orchestration\Support\PaymentOrchestrationMetrics;
use App\Services\Payment\Settlement\SettlementFeeEstimator;
use App\Support\CurrencyMinorUnit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/** Sole approved PaymentAttempt/PaymentRefund/ledger mutation boundary for Plan 047. */
final class EloquentPaymentPersistence implements PaymentPersistencePort
{
    /** @var list<PaymentRefundStateEnum> */
    private const ACTIVE_REFUND_STATES = [
        PaymentRefundStateEnum::Prepared,
        PaymentRefundStateEnum::Submitted,
        PaymentRefundStateEnum::Pending,
        PaymentRefundStateEnum::ReconciliationRequired,
    ];

    public function __construct(
        private readonly OrderPaymentLedgerWriter $ledger,
        private readonly PaymentQueryPort $query,
        private readonly SettlementFeeEstimator $feeEstimator,
        // #1594 — cổng ĐỌC của Ordering. `EloquentOrderQuery` được dựng ở #1544
        // với đúng class này làm người dùng đầu tiên (docblock của nó nói thẳng
        // "EloquentPaymentPersistence already takes this lock by hand today"),
        // rồi không ai nối vào. Nối ở đây.
        private readonly OrderQueryPort $orders,
    ) {}

    public function reserveAttempt(ReserveVerifiedPaymentAttemptCommand $command): PaymentPrepareResult
    {
        $command->assertTrusted();

        return DB::transaction(function () use ($command): PaymentPrepareResult {
            $existing = PaymentAttempt::query()
                ->where('organization_id', $command->context->organizationId)
                ->where('id', $command->attemptId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return new PaymentPrepareResult(
                    (string) $existing->id,
                    (int) $existing->version,
                    $this->attemptOutcomeFromModel($existing),
                );
            }

            $attempt = $this->prepareAttemptSkeleton($command);
            PaymentOrchestrationMetrics::incrementAttemptState(
                $attempt->state->value,
                PaymentOrchestrationLogContext::enrich($command->context, [
                    'attempt_id' => $attempt->id,
                    'order_id' => $attempt->customer_order_id,
                ]),
            );

            return new PaymentPrepareResult(
                (string) $attempt->id,
                (int) $attempt->version,
                $this->attemptOutcomeFromModel($attempt),
            );
        });
    }

    /**
     * Convert a minor-unit integer to the major-unit value stored in the
     * decimal(15,2) order_payments columns, using the ISO currency exponent.
     */
    private function minorToMajor(int $minor, string $currency): float
    {
        return $minor / (10 ** CurrencyMinorUnit::exponent($currency));
    }

    public function recordTender(RecordResolvedPaymentTenderCommand $command): PaymentFinalizeResult
    {
        $command->assertTrusted();

        return DB::transaction(function () use ($command): PaymentFinalizeResult {
            // Khoá hàng đơn để hai webhook cùng đơn không chạy song song. Kết
            // quả cố ý bỏ đi — thứ cần là CÁI KHOÁ, không phải dữ liệu. Cổng giữ
            // `lockForUpdate()` bên trong `findForSettlement()`, nên ngữ nghĩa
            // khoá không đổi khi bỏ model đi.
            $this->orders->findForSettlement(
                $command->context->organizationId ?? throw new RuntimeException('Tenant is missing.'),
                $command->orderId,
            ) ?? throw new RuntimeException('Order not found: '.$command->orderId);

            $existing = $this->ledger->findByOrderAndIdempotencyKey(
                $command->orderId,
                $command->context->revealIdempotencyKey(),
            );

            if ($existing !== null) {
                $attemptVersion = 1;
                if ($existing->payment_attempt_id !== null) {
                    $attemptVersion = (int) (PaymentAttempt::query()->find($existing->payment_attempt_id)?->version ?? 1);
                }

                return $this->finalizeResultForOrder(
                    $command->context->organizationId ?? throw new RuntimeException('Tenant is missing.'),
                    $this->resolveAttemptIdForPayment($existing),
                    $attemptVersion,
                    PaymentAttemptStateEnum::Succeeded,
                    $command->orderId,
                    new PaymentAttemptOutcome(
                        PaymentAttemptStateEnum::Succeeded,
                        new ProviderObjectReference('ledger:'.$existing->id),
                        $command->amount,
                        null,
                    ),
                );
            }

            $order = $this->orders->findById(
                $command->context->organizationId ?? throw new RuntimeException('Tenant is missing.'),
                $command->orderId,
            ) ?? throw new RuntimeException('Order not found: '.$command->orderId);
            $payment = $this->ledger->createRow([
                'id' => $command->orderPaymentId,
                'customer_order_id' => $command->orderId,
                'payment_method_id' => $command->method->paymentMethodId,
                'organization_id' => $command->context->organizationId,
                'brand_id' => $order->brandId(),
                'branch_id' => $command->branchId,
                // order_payments.* are MAJOR-unit decimal(15,2) columns; the
                // tender command carries MINOR units. Convert with the currency
                // exponent (JPY=0-decimal → unchanged; USD=2 → /100) so a
                // 2-decimal tenant is not settled at 100x once the orchestrator
                // runtime is enabled.
                'amount' => $this->minorToMajor($command->amount->minorAmount, $command->amount->currency),
                'tip_amount' => $this->minorToMajor($command->tender->tipMinor, $command->amount->currency),
                'tendered_amount' => $command->tender->tenderedMinor === null
                    ? null
                    : $this->minorToMajor($command->tender->tenderedMinor, $command->amount->currency),
                'change_amount' => $command->changeMinor === null
                    ? null
                    : $this->minorToMajor($command->changeMinor, $command->amount->currency),
                'status' => PaymentStatusEnum::Succeeded->value,
                'paid_at' => now(),
                'received_by_id' => $command->context->actorId,
                'idempotency_key' => $command->context->revealIdempotencyKey(),
                'reference_no' => $command->tender->reference,
                // order_payments.metadata is caller-owned split-bill data and must
                // stay NULL when the caller sent none (#1058). These two attempt
                // fingerprints were written here but read NOWHERE, and the
                // authoritative copy already lives on payment_attempts
                // (request_fingerprint). Keeping them here only broke the contract.
                'metadata' => null,
            ]);

            PaymentOrchestrationMetrics::incrementAttemptState(
                PaymentAttemptStateEnum::Succeeded->value,
                PaymentOrchestrationLogContext::enrich($command->context, [
                    'order_payment_id' => $payment->id,
                    'order_id' => $command->orderId,
                ]),
            );

            return $this->finalizeResultForOrder(
                (string) $command->context->organizationId,
                $this->resolveAttemptIdForPayment($payment),
                1,
                PaymentAttemptStateEnum::Succeeded,
                $command->orderId,
                new PaymentAttemptOutcome(
                    PaymentAttemptStateEnum::Succeeded,
                    new ProviderObjectReference('ledger:'.$payment->id),
                    $command->amount,
                    null,
                ),
            );
        });
    }

    public function finalizeAttempt(FinalizePaymentCommand $command): PaymentFinalizeResult
    {
        return DB::transaction(function () use ($command): PaymentFinalizeResult {
            $attempt = PaymentAttempt::query()
                ->where('organization_id', $command->context->organizationId)
                ->where('id', $command->attemptId)
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                throw (new ModelNotFoundException)->setModel(PaymentAttempt::class, [$command->attemptId]);
            }

            $evidence = $command->evidence;
            $attempt->update([
                'state' => $evidence->state->value,
                // Keep the reference we already hold when evidence carries none
                // (a declined/failed result has no provider payment identity).
                // Without the fallback this NULLs the column, and
                // (connection_id, provider_object_id) is the only key a later
                // webhook has to find this attempt by — one failed finalize
                // used to make every subsequent notification unmatchable.
                // markAttemptForReconciliation below has always done this.
                'provider_object_id' => $evidence->payment?->value ?? $attempt->provider_object_id,
                'provider_status' => $evidence->rawStatus,
                'next_action' => $evidence->nextAction?->toClientArray(),
                'redacted_summary' => $evidence->summary->jsonSerialize(),
                // Plan-050 L1 — dashboard-only fee estimate stamped at the
                // succeeded transition. Never authoritative (G1): booked fees
                // come exclusively from payment_settlements.
                'estimated_fee_minor' => $this->stampedEstimatedFeeMinor($attempt, $evidence->state),
                'finalized_at' => in_array($evidence->state, [PaymentAttemptStateEnum::Succeeded, PaymentAttemptStateEnum::Failed, PaymentAttemptStateEnum::Canceled, PaymentAttemptStateEnum::Expired], true)
                    ? now()
                    : $attempt->finalized_at,
                'version' => (int) $attempt->version + 1,
            ]);

            $attempt = $attempt->fresh() ?? $attempt;

            PaymentOrchestrationMetrics::incrementAttemptState(
                $evidence->state->value,
                PaymentOrchestrationLogContext::enrich($command->context, [
                    'attempt_id' => $attempt->id,
                    'order_id' => $attempt->customer_order_id,
                ]),
            );

            return $this->finalizeResultForOrder(
                (string) $attempt->organization_id,
                (string) $attempt->id,
                (int) $attempt->version,
                $evidence->state,
                (string) $attempt->customer_order_id,
                $this->attemptOutcomeFromGateway($evidence),
            );
        });
    }

    public function markAttemptForReconciliation(ReconcilePaymentCommand $command): PaymentFinalizeResult
    {
        return DB::transaction(function () use ($command): PaymentFinalizeResult {
            $attempt = PaymentAttempt::query()
                ->where('organization_id', $command->context->organizationId)
                ->where('id', $command->attemptId)
                ->lockForUpdate()
                ->firstOrFail();

            $evidence = $command->evidence;
            $attempt->update([
                'state' => $evidence->state->value,
                'provider_object_id' => $evidence->payment?->value ?? $attempt->provider_object_id,
                'provider_status' => $evidence->rawStatus,
                'next_action' => $evidence->nextAction?->toClientArray(),
                'redacted_summary' => $evidence->summary->jsonSerialize(),
                'retry_count' => (int) $attempt->retry_count + 1,
                'next_reconciliation_at' => now()->addMinutes(5),
                // Plan-050 L1 — see finalizeAttempt; the reconcile sweep is the
                // other path an attempt reaches `succeeded` through.
                'estimated_fee_minor' => $this->stampedEstimatedFeeMinor($attempt, $evidence->state),
                'finalized_at' => $evidence->state === PaymentAttemptStateEnum::Succeeded ? now() : $attempt->finalized_at,
                'version' => (int) $attempt->version + 1,
            ]);

            $attempt = $attempt->fresh() ?? $attempt;

            PaymentOrchestrationMetrics::incrementAttemptState(
                $evidence->state->value,
                PaymentOrchestrationLogContext::enrich($command->context, [
                    'attempt_id' => $attempt->id,
                    'order_id' => $attempt->customer_order_id,
                    'reconcile' => true,
                ]),
            );

            return $this->finalizeResultForOrder(
                (string) $attempt->organization_id,
                (string) $attempt->id,
                (int) $attempt->version,
                $evidence->state,
                (string) $attempt->customer_order_id,
                $this->attemptOutcomeFromGateway($evidence),
            );
        });
    }

    public function reserveRefund(ReserveVerifiedRefundCommand $command): PaymentRefundResult
    {
        $command->assertTrusted();
        $intent = $command->intent;
        $limit = (int) config('payments.max_concurrent_refunds_per_payment', 3);

        return DB::transaction(function () use ($command, $intent, $limit): PaymentRefundResult {
            PaymentAttempt::query()
                ->where('organization_id', $intent->organizationId)
                ->where('id', $intent->attemptId)
                ->lockForUpdate()
                ->firstOrFail();

            $activeRefunds = PaymentRefund::query()
                ->where('payment_attempt_id', $intent->attemptId)
                ->whereIn('state', array_map(static fn (PaymentRefundStateEnum $state): string => $state->value, self::ACTIVE_REFUND_STATES))
                ->lockForUpdate()
                ->count();

            if ($activeRefunds >= $limit) {
                throw new InvalidArgumentException("Concurrent refund limit ({$limit}) reached for this payment.");
            }

            $existing = PaymentRefund::query()
                ->where('organization_id', $intent->organizationId)
                ->where('id', $intent->refundId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return new PaymentRefundResult(
                    (string) $existing->id,
                    (int) $existing->version,
                    $existing->state instanceof PaymentRefundStateEnum ? $existing->state : PaymentRefundStateEnum::from($existing->state),
                    $existing->provider_refund_id === null ? null : new ProviderObjectReference((string) $existing->provider_refund_id),
                    null,
                );
            }

            $attempt = PaymentAttempt::query()->findOrFail($intent->attemptId);
            $refund = PaymentRefund::query()->create([
                'id' => $intent->refundId,
                'organization_id' => $intent->organizationId,
                'branch_id' => $intent->branchId,
                'payment_attempt_id' => $intent->attemptId,
                'connection_id' => $attempt->connection_id,
                'state' => PaymentRefundStateEnum::Prepared->value,
                'provider' => $attempt->provider,
                'environment' => $attempt->environment,
                'currency' => $intent->currencyCode,
                'amount_minor' => $intent->amountMinor,
                'idempotency_key' => $intent->idempotencyKeyHash,
                'request_fingerprint' => $intent->requestFingerprint,
                'provider_request_key' => $intent->refundRequestId,
                'reason_code' => $intent->reason->value,
                'version' => 1,
                'prepared_at' => now(),
            ]);

            PaymentOrchestrationMetrics::incrementRefundState(
                PaymentRefundStateEnum::Prepared->value,
                PaymentOrchestrationLogContext::enrich($command->context, [
                    'refund_id' => $refund->id,
                    'attempt_id' => $intent->attemptId,
                ]),
            );

            return new PaymentRefundResult(
                (string) $refund->id,
                (int) $refund->version,
                PaymentRefundStateEnum::Prepared,
                null,
                null,
            );
        });
    }

    public function finalizeRefund(ReconcilePaymentRefundCommand $command): PaymentRefundResult
    {
        return DB::transaction(function () use ($command): PaymentRefundResult {
            $refund = PaymentRefund::query()
                ->where('organization_id', $command->context->organizationId)
                ->where('id', $command->refundId)
                ->lockForUpdate()
                ->firstOrFail();

            $evidence = $command->evidence;
            $refund->update([
                'state' => $evidence->state->value,
                'provider_refund_id' => $evidence->refund?->value,
                'provider_status' => $evidence->rawStatus,
                'redacted_summary' => $evidence->summary->jsonSerialize(),
                'submitted_at' => $refund->submitted_at ?? ($evidence->state === PaymentRefundStateEnum::Pending ? now() : null),
                'finalized_at' => in_array($evidence->state, [PaymentRefundStateEnum::Succeeded, PaymentRefundStateEnum::Failed, PaymentRefundStateEnum::Canceled], true)
                    ? now()
                    : $refund->finalized_at,
                'version' => (int) $refund->version + 1,
            ]);

            $refund = $refund->fresh() ?? $refund;

            PaymentOrchestrationMetrics::incrementRefundState(
                $evidence->state->value,
                PaymentOrchestrationLogContext::enrich($command->context, [
                    'refund_id' => $refund->id,
                    'attempt_id' => $refund->payment_attempt_id,
                ]),
            );

            return new PaymentRefundResult(
                (string) $refund->id,
                (int) $refund->version,
                $evidence->state,
                $evidence->refund,
                $evidence->processedMoney,
            );
        });
    }

    public function recordVerifiedProviderEvent(ProcessProviderEventCommand $command): ProviderEventResult
    {
        $event = $command->event;
        $connection = PaymentGatewayConnection::query()->with('provider')->findOrFail($command->connectionId);

        return DB::transaction(function () use ($command, $event, $connection): ProviderEventResult {
            $existing = PaymentProviderEvent::query()
                ->where('connection_id', $command->connectionId)
                ->where('provider_event_id', $event->providerEventId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->payload_hash !== $event->payloadSha256) {
                    throw new WebhookPayloadConflict($command->context->correlationId);
                }

                $existing->update([
                    'delivery_count' => (int) $existing->delivery_count + 1,
                ]);

                return new ProviderEventResult((string) $existing->id, true, null);
            }

            $record = PaymentProviderEvent::query()->create([
                'organization_id' => $connection->organization_id,
                'connection_id' => $command->connectionId,
                'provider' => $connection->provider->code,
                'environment' => $connection->environment,
                'state' => PaymentProviderEventStateEnum::ReceivedVerified->value,
                'provider_event_id' => $event->providerEventId,
                'event_type' => $event->eventType,
                'provider_object_id' => $event->payment?->value ?? $event->refund?->value,
                'payload_hash' => $event->payloadSha256,
                'redacted_payload' => $event->payload->jsonSerialize(),
                'received_at' => now(),
                'verified_at' => now(),
            ]);

            Log::channel('payment_orchestration')->info('provider_event_recorded', PaymentOrchestrationLogContext::enrich(
                $command->context,
                [
                    'provider_event_id' => $record->id,
                    'connection_id' => $command->connectionId,
                    'external_event_id' => $event->providerEventId,
                ],
            ));

            return new ProviderEventResult((string) $record->id, false, null);
        });
    }

    private function prepareAttemptSkeleton(ReserveVerifiedPaymentAttemptCommand $command): PaymentAttempt
    {
        $verification = $command->verification;
        $connection = PaymentGatewayConnection::query()->with('provider')->findOrFail($verification->connectionId);
        $order = $this->orders->findById($verification->organizationId, $verification->orderId)
            ?? throw new RuntimeException('Order not found: '.$verification->orderId);

        return PaymentAttempt::query()->create([
            'id' => $command->attemptId,
            'organization_id' => $verification->organizationId,
            'brand_id' => $order->brandId(),
            'branch_id' => $verification->branchId,
            'customer_order_id' => $verification->orderId,
            'connection_id' => $verification->connectionId,
            'connection_option_id' => $verification->connectionOptionId,
            'policy_revision_id' => $this->resolvePolicyRevisionId($verification->branchId, $verification->policyRevision),
            'operation' => PaymentAttemptOperationEnum::Sale->value,
            'state' => PaymentAttemptStateEnum::Prepared->value,
            'provider' => $connection->provider->code,
            'environment' => $connection->environment,
            'owner_scope' => $connection->owner_scope,
            'operator_org_unit_id' => $connection->operator_org_unit_id,
            'ownership_revision' => $connection->ownership_revision,
            'channel' => PaymentChannelEnum::Pos->value,
            'currency' => $command->currencyCode,
            'amount_minor' => $command->amountMinor,
            'idempotency_key' => $command->context->idempotencyKeyHash,
            'request_fingerprint' => $verification->requestFingerprint,
            'provider_request_key' => $command->attemptId,
            'version' => 1,
            'prepared_at' => now(),
        ]);
    }

    /**
     * Plan-050 L1 (T1.2, #1155) — the narrowest hook where an attempt
     * reaches `succeeded`: compute the DASHBOARD-ONLY estimated gateway fee
     * from the connection option's declared fee_estimate. Keeps an existing
     * stamp (idempotent under webhook + reconcile double-delivery), stays
     * null when nothing is declared, and never blocks the money path — an
     * estimator fault degrades to "no estimate".
     */
    private function stampedEstimatedFeeMinor(PaymentAttempt $attempt, PaymentAttemptStateEnum $state): ?int
    {
        if ($attempt->estimated_fee_minor !== null) {
            return (int) $attempt->estimated_fee_minor;
        }

        if ($state !== PaymentAttemptStateEnum::Succeeded) {
            return null;
        }

        try {
            return $this->feeEstimator->estimateForAttempt($attempt);
        } catch (\Throwable $e) {
            Log::channel('payment_orchestration')->warning('estimated_fee_stamp_failed', [
                'attempt_id' => $attempt->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolvePolicyRevisionId(string $branchId, int $revision): string
    {
        $record = PaymentPolicyRevision::query()
            ->where('branch_id', $branchId)
            ->where('revision', $revision)
            ->first();

        if ($record === null) {
            throw new InvalidArgumentException("Payment policy revision {$revision} was not found for branch {$branchId}.");
        }

        return (string) $record->id;
    }

    private function finalizeResultForOrder(
        string $organizationId,
        string $attemptId,
        int $version,
        PaymentAttemptStateEnum $state,
        string $orderId,
        ?PaymentAttemptOutcome $outcome = null,
    ): PaymentFinalizeResult {
        $ledgerNetMinor = $this->query->ledgerNetMinorForOrder($organizationId, $orderId);
        // Ordering tự trả lời "đã trả đủ chưa" — dung sai làm tròn của nó phụ
        // thuộc `shop_order_settings.currency_code`, và đọc sai nguồn tiền tệ
        // từng ghi nhận 1.99 USD doanh thu ma (#821 E3).
        $orderSettlementRequired = $state === PaymentAttemptStateEnum::Succeeded
            && $this->orders->isPaidInFull($organizationId, $orderId);

        return new PaymentFinalizeResult(
            $attemptId,
            $version,
            $outcome ?? new PaymentAttemptOutcome($state, null, null, null),
            $ledgerNetMinor,
            $orderSettlementRequired,
        );
    }

    private function attemptOutcomeFromModel(PaymentAttempt $attempt): PaymentAttemptOutcome
    {
        $state = $attempt->state instanceof PaymentAttemptStateEnum
            ? $attempt->state
            : PaymentAttemptStateEnum::from($attempt->state);

        return new PaymentAttemptOutcome(
            $state,
            $attempt->provider_object_id === null ? null : new ProviderObjectReference((string) $attempt->provider_object_id),
            null,
            null,
        );
    }

    private function attemptOutcomeFromGateway(GatewayPaymentResult $evidence): PaymentAttemptOutcome
    {
        return new PaymentAttemptOutcome(
            $evidence->state,
            $evidence->payment,
            $evidence->processedMoney,
            $evidence->nextAction,
        );
    }

    private function resolveAttemptIdForPayment(OrderPayment $payment): string
    {
        if ($payment->payment_attempt_id !== null) {
            return (string) $payment->payment_attempt_id;
        }

        return (string) $payment->id;
    }

    public function attachCustomerWebPrepareReference(AttachCustomerWebPrepareReferenceCommand $command): PrepareReferenceAttachmentResult
    {
        $updated = PaymentAttempt::query()
            ->where('organization_id', $command->context->organizationId)
            ->where('id', $command->attemptId)
            ->update([
                'provider_object_id' => $command->providerObjectId,
                'channel' => PaymentChannelEnum::CustomerWeb->value,
            ]);

        return new PrepareReferenceAttachmentResult($command->attemptId, $updated > 0);
    }

    /**
     * #1108 — sweep backoff bookkeeping. The reconcile command's candidate
     * query orders by next_reconciliation_at (NULLs first), so an attempt
     * whose recovery keeps throwing must be pushed out of the window head or
     * it starves healthy candidates. Scheduling columns only — the attempt
     * state machine is untouched.
     */
    public function deferAttemptReconciliation(PaymentAttempt $attempt): void
    {
        $attempt->forceFill([
            'retry_count' => (int) $attempt->retry_count + 1,
            'next_reconciliation_at' => now()->addMinutes(
                min(720, 30 * max(1, (int) $attempt->retry_count)),
            ),
        ])->save();
    }
}
