<?php

namespace App\Services\Payment\Settlement\Stripe;

use App\Models\GatewayPayout;
use App\Models\OrderPayment;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentSettlement;
use App\Services\Payment\ProviderEvent\StripeIntentOrigin;
use App\Services\Payment\Settlement\Enums\GatewayPayoutStatus;
use App\Services\Payment\Settlement\Enums\SettlementKind;
use App\Services\Payment\Settlement\Enums\SettlementSource;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\SettlementRowAssembler;
use App\Support\Logging\MoneyOrchestrationLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\BalanceTransaction;

/**
 * Plan-050 M2 (#1155) — Stripe API-driven settlement ingest.
 *
 * Turns verified provider events (plan-048 inbox rows) into
 * payment_settlements / gateway_payouts rows. The gateway's balance
 * transaction is the ONLY source of fee truth (G1): nothing here multiplies
 * a rate. All amounts land exactly as Stripe reports them, signed (S-11);
 * fee tax comes from `fee_details` entries of type `tax` when present and is
 * 0 otherwise — JP card fees are 非課税 by statement, not by hardcode (S-16).
 *
 * Every ingest is idempotent through the (provider, external_ref) DB
 * constraint — a redelivered webhook or a payout listing that repeats an
 * already-ingested transaction resolves to the existing row (S-02, G3).
 *
 * This recorder is FAIL-OPEN relative to the money pipeline: it is invoked
 * after the ledger outcome in ProviderEventApplicator and its failures are
 * logged, never allowed to dead-letter a payment event. The daily
 * `settlements:reconcile` sweep (direction A) is the backstop that surfaces
 * anything missed (G6).
 */
final class StripeSettlementRecorder
{
    public function __construct(
        private readonly StripeSettlementClient $client,
        private readonly SettlementRowAssembler $assembler,
    ) {}

    /**
     * Route a Stripe inbox event into settlement rows. Returns a short
     * outcome string for structured logging, or null when the event type
     * carries no settlement meaning.
     */
    public function applyProviderEvent(PaymentProviderEvent $event): ?string
    {
        return match ($event->event_type) {
            'payment_intent.succeeded' => $this->recordPaymentSettlement($event),
            'charge.refunded' => $this->recordRefundSettlements($event),
            'charge.dispute.created',
            'charge.dispute.funds_withdrawn' => $this->recordDisputeWithdrawal($event),
            'charge.dispute.closed',
            'charge.dispute.funds_reinstated' => $this->recordDisputeReversal($event),
            'payout.paid' => $this->applyPayoutPaid($event),
            'payout.failed' => $this->applyPayoutFailed($event),
            default => null,
        };
    }

    /**
     * T2.1 — succeeded charge → kind=payment row from its balance
     * transaction. Orphan (S-19) when no local attempt/ledger row matches
     * yet (offline replay may land later; the reconcile sweep re-matches).
     */
    private function recordPaymentSettlement(PaymentProviderEvent $event): string
    {
        $connection = PaymentGatewayConnection::query()->findOrFail($event->connection_id);
        $payload = is_array($event->redacted_payload) ? $event->redacted_payload : [];
        $intent = $payload['intent_snapshot'] ?? null;

        if (! is_array($intent)) {
            return 'settlement_skipped_no_intent_snapshot';
        }

        $intentId = is_string($intent['id'] ?? null) ? $intent['id'] : '';

        if ($this->isForeignAccountMoney($intent, $intentId, (string) $event->event_type)) {
            return 'settlement_skipped_foreign_account';
        }

        $chargeId = $this->chargeIdFromIntentSnapshot($intent);

        if ($chargeId === null) {
            return 'settlement_skipped_no_charge';
        }

        $charge = $this->client->retrieveCharge($connection, $chargeId);
        $balanceTransactionId = is_string($charge->balance_transaction ?? null)
            ? $charge->balance_transaction
            : ($charge->balance_transaction->id ?? null);

        if (! is_string($balanceTransactionId) || $balanceTransactionId === '') {
            // A charge with no balance transaction has not touched the Stripe
            // balance (e.g. still pending capture) — nothing to settle yet.
            return 'settlement_skipped_no_balance_transaction';
        }

        $transaction = $this->client->retrieveBalanceTransaction($connection, $balanceTransactionId);

        $attempt = $intentId === '' ? null : PaymentAttempt::query()
            ->where('connection_id', $connection->id)
            ->where('provider_object_id', $intentId)
            ->first();
        $orderPayment = $intentId === '' ? null : OrderPayment::query()
            ->where('reference_no', $intentId)
            ->first();

        [$feeMinor, $feeTaxMinor] = $this->splitFeeAndTax($transaction);
        $currency = strtoupper((string) $transaction->currency);

        $status = SettlementStatus::PendingPayout;
        $metadata = ['provider_object_id' => $intentId, 'charge_id' => $chargeId];

        if ($attempt === null && $orderPayment === null) {
            // S-19 — settlement truth arrived before the payment row (offline
            // replay). Keep it, flag it, let the reconcile sweep re-match.
            $status = SettlementStatus::Orphan;
        } elseif ($attempt !== null && strtoupper((string) $attempt->currency) !== $currency) {
            // S-17 — currency disagreement is never converted, only flagged.
            $status = SettlementStatus::Mismatch;
            $metadata['currency_mismatch'] = [
                'attempt_currency' => strtoupper((string) $attempt->currency),
                'settlement_currency' => $currency,
            ];
        }

        [, $created] = $this->assembler->persistIdempotent($this->assembler->assemble(
            $connection,
            SettlementKind::Payment,
            SettlementSource::Api,
            (string) $transaction->id,
            (int) $transaction->amount,
            $feeMinor,
            $feeTaxMinor,
            (int) $transaction->net,
            $currency,
            $this->timestamp($transaction->created ?? null),
            $status,
            $orderPayment?->id,
            $attempt?->id,
            metadata: $metadata,
        ));

        return $created ? 'settlement_payment_recorded' : 'settlement_payment_duplicate';
    }

    /**
     * T2.2 — refund rows. One row per refund balance transaction; the fee is
     * whatever the statement says (Stripe keeps the original charge fee, so
     * the refund txn itself reports fee 0 — S-07 by data, not by hardcode).
     */
    private function recordRefundSettlements(PaymentProviderEvent $event): string
    {
        $connection = PaymentGatewayConnection::query()->findOrFail($event->connection_id);
        $payload = is_array($event->redacted_payload) ? $event->redacted_payload : [];
        $charge = $payload['charge_snapshot'] ?? null;

        if (! is_array($charge)) {
            return 'settlement_skipped_no_charge_snapshot';
        }

        $intentId = is_string($charge['payment_intent'] ?? null) ? $charge['payment_intent'] : '';

        // Charge do PaymentIntent sinh ra thừa hưởng metadata của intent, nên
        // hoàn tiền của trang WooCommerce cũng nhận diện được bằng đúng phép
        // trên — và cũng không được vào sổ của Tempo.
        if ($this->isForeignAccountMoney($charge, $intentId, (string) $event->event_type)) {
            return 'settlement_skipped_foreign_account';
        }

        $attempt = $intentId === '' ? null : PaymentAttempt::query()
            ->where('connection_id', $connection->id)
            ->where('provider_object_id', $intentId)
            ->first();
        $orderPayment = $intentId === '' ? null : OrderPayment::query()
            ->where('reference_no', $intentId)
            ->first();

        $recorded = 0;
        foreach (($charge['refunds']['data'] ?? []) as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            $balanceTransactionId = $refund['balance_transaction'] ?? null;
            if (! is_string($balanceTransactionId) || $balanceTransactionId === '') {
                continue;
            }

            $transaction = $this->client->retrieveBalanceTransaction($connection, $balanceTransactionId);
            [$feeMinor, $feeTaxMinor] = $this->splitFeeAndTax($transaction);

            [, $created] = $this->assembler->persistIdempotent($this->assembler->assemble(
                $connection,
                SettlementKind::Refund,
                SettlementSource::Api,
                (string) $transaction->id,
                (int) $transaction->amount,
                $feeMinor,
                $feeTaxMinor,
                (int) $transaction->net,
                strtoupper((string) $transaction->currency),
                $this->timestamp($transaction->created ?? null),
                ($attempt === null && $orderPayment === null) ? SettlementStatus::Orphan : SettlementStatus::PendingPayout,
                $orderPayment?->id,
                $attempt?->id,
                metadata: [
                    'provider_object_id' => $intentId,
                    'refund_id' => is_string($refund['id'] ?? null) ? $refund['id'] : null,
                ],
            ));

            if ($created) {
                $recorded++;
            }
        }

        return $recorded > 0 ? "settlement_refunds_recorded:{$recorded}" : 'settlement_refunds_duplicate_or_empty';
    }

    /**
     * T2.2 (S-08) — dispute funds withdrawn: TWO rows from the negative
     * balance transaction — `dispute_withdrawal` (the disputed gross) and
     * `dispute_fee` (Stripe's dispute fee, ¥1,500 in JP, read from the txn,
     * never hardcoded). Their nets sum to the transaction net.
     */
    private function recordDisputeWithdrawal(PaymentProviderEvent $event): string
    {
        return $this->recordDisputeRows($event, negative: true);
    }

    /**
     * T2.2 (S-08) — dispute WON: the reinstatement transaction (positive
     * amount, fee refunded) becomes an APPEND-ONLY `dispute_reversal` row.
     * Withdrawal rows are never edited — matching the #1123 ledger
     * reinstatement philosophy.
     */
    private function recordDisputeReversal(PaymentProviderEvent $event): string
    {
        return $this->recordDisputeRows($event, negative: false);
    }

    private function recordDisputeRows(PaymentProviderEvent $event, bool $negative): string
    {
        $connection = PaymentGatewayConnection::query()->findOrFail($event->connection_id);
        $payload = is_array($event->redacted_payload) ? $event->redacted_payload : [];
        $dispute = $payload['dispute_snapshot'] ?? null;

        if (! is_array($dispute)) {
            return 'settlement_skipped_no_dispute_snapshot';
        }

        $transactions = $dispute['balance_transactions'] ?? [];
        if (! is_array($transactions) || $transactions === []) {
            return 'settlement_skipped_no_dispute_balance_txn';
        }

        $intentId = $this->disputeIntentId($dispute);
        $attempt = $intentId === '' ? null : PaymentAttempt::query()
            ->where('connection_id', $connection->id)
            ->where('provider_object_id', $intentId)
            ->first();
        $orderPayment = $intentId === '' ? null : OrderPayment::query()
            ->where('reference_no', $intentId)
            ->first();

        $disputeId = is_string($dispute['id'] ?? null) ? $dispute['id'] : null;
        $recorded = 0;

        foreach ($transactions as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $transaction = BalanceTransaction::constructFrom($raw);
            $amount = (int) $transaction->amount;

            if ($negative ? $amount >= 0 : $amount <= 0) {
                continue;
            }

            [$feeMinor, $feeTaxMinor] = $this->splitFeeAndTax($transaction);
            $currency = strtoupper((string) $transaction->currency);
            $settledAt = $this->timestamp($transaction->created ?? null);
            $metadata = ['dispute_id' => $disputeId, 'provider_object_id' => $intentId];

            if ($negative) {
                // Withdrawal txn: amount = -disputed gross, fee = dispute fee.
                // Split so each concept is its own row (S-08), nets summing to
                // the transaction net.
                [, $createdWithdrawal] = $this->assembler->persistIdempotent($this->assembler->assemble(
                    $connection,
                    SettlementKind::DisputeWithdrawal,
                    SettlementSource::Api,
                    (string) $transaction->id,
                    $amount,
                    0,
                    0,
                    $amount,
                    $currency,
                    $settledAt,
                    SettlementStatus::PendingPayout,
                    $orderPayment?->id,
                    $attempt?->id,
                    metadata: $metadata,
                ));
                $recorded += $createdWithdrawal ? 1 : 0;

                if ($feeMinor !== 0 || $feeTaxMinor !== 0) {
                    [, $createdFee] = $this->assembler->persistIdempotent($this->assembler->assemble(
                        $connection,
                        SettlementKind::DisputeFee,
                        SettlementSource::Api,
                        $transaction->id.':fee',
                        0,
                        $feeMinor,
                        $feeTaxMinor,
                        -$feeMinor - $feeTaxMinor,
                        $currency,
                        $settledAt,
                        SettlementStatus::PendingPayout,
                        $orderPayment?->id,
                        $attempt?->id,
                        metadata: $metadata,
                    ));
                    $recorded += $createdFee ? 1 : 0;
                }

                continue;
            }

            // Reinstatement txn: positive amount, negative fee (fee refunded).
            [, $createdReversal] = $this->assembler->persistIdempotent($this->assembler->assemble(
                $connection,
                SettlementKind::DisputeReversal,
                SettlementSource::Api,
                (string) $transaction->id,
                $amount,
                $feeMinor,
                $feeTaxMinor,
                (int) $transaction->net,
                $currency,
                $settledAt,
                SettlementStatus::PendingPayout,
                $orderPayment?->id,
                $attempt?->id,
                metadata: $metadata,
            ));
            $recorded += $createdReversal ? 1 : 0;
        }

        return $recorded > 0 ? "settlement_dispute_rows_recorded:{$recorded}" : 'settlement_dispute_duplicate_or_empty';
    }

    /**
     * T2.3 (S-12) — payout.paid: upsert the GatewayPayout, list the payout's
     * balance transactions, attach payout_id to the matching settlement rows
     * (backfilling missed rows from the listing itself — unknown types become
     * kind=manual, S-13), then VERIFY Σ net == payout net. A shortfall marks
     * the payout `mismatch` and is never auto-balanced with a synthetic row.
     */
    private function applyPayoutPaid(PaymentProviderEvent $event): string
    {
        $connection = PaymentGatewayConnection::query()->findOrFail($event->connection_id);
        $snapshot = $this->payoutSnapshot($event);

        if ($snapshot === null) {
            return 'settlement_skipped_no_payout_reference';
        }

        $payoutId = (string) $snapshot['id'];
        $provider = $this->providerCode($connection);

        $payout = $this->upsertPayout($connection, $provider, $payoutId, $snapshot, GatewayPayoutStatus::Paid);

        $transactions = $this->client->listBalanceTransactionsForPayout($connection, $payoutId);

        return DB::transaction(function () use ($connection, $provider, $payout, $transactions): string {
            foreach ($transactions as $transaction) {
                if ((string) $transaction->type === 'payout') {
                    // The payout's own negative balance transaction — the
                    // transfer itself, not a money-event to settle.
                    continue;
                }

                $refs = [(string) $transaction->id, $transaction->id.':fee'];
                $rows = PaymentSettlement::query()
                    ->where('provider', $provider)
                    ->whereIn('external_ref', $refs)
                    ->get();

                if ($rows->isEmpty()) {
                    $rows = collect([$this->backfillRowFromPayoutTransaction($connection, $transaction)]);
                }

                foreach ($rows as $row) {
                    if ($row->payout_id !== null && $row->payout_id !== $payout->id) {
                        // Never steal a row already attached to another payout
                        // — surface it instead (direction B will alert).
                        Log::channel('payment_orchestration')->warning('settlement_row_payout_conflict', [
                            'settlement_id' => $row->id,
                            'existing_payout_id' => $row->payout_id,
                            'incoming_payout_id' => $payout->id,
                        ]);

                        continue;
                    }

                    if ($row->payout_id === null) {
                        $row->update(['payout_id' => $payout->id]);
                    }
                }
            }

            $attachedRows = PaymentSettlement::query()
                ->where('payout_id', $payout->id)
                ->get();

            $attachedNet = (int) $attachedRows->sum('net_minor');
            $expectedNet = (int) $payout->net_minor;

            if ($attachedNet === $expectedNet) {
                PaymentSettlement::query()
                    ->where('payout_id', $payout->id)
                    ->where('status', SettlementStatus::PendingPayout->value)
                    ->update(['status' => SettlementStatus::Reconciled->value, 'updated_at' => now()]);

                $payout->update([
                    'status' => GatewayPayoutStatus::Paid,
                    'reconciled_at' => now(),
                ]);

                return 'settlement_payout_reconciled';
            }

            // S-12 [HARD] — never auto-balance. Keep the evidence and shout.
            $payout->update([
                'status' => GatewayPayoutStatus::Mismatch,
                'reconciled_at' => null,
                'metadata' => array_merge($payout->metadata ?? [], [
                    'reconciliation_mismatch' => [
                        'expected_net_minor' => $expectedNet,
                        'attached_net_minor' => $attachedNet,
                        'attached_row_count' => $attachedRows->count(),
                        'checked_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);

            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_STRANDED, 'settlement_payout_mismatch', [
                'payout_id' => $payout->id,
                'external_payout_id' => $payout->external_payout_id,
                'expected_net_minor' => $expectedNet,
                'attached_net_minor' => $attachedNet,
            ]);

            return 'settlement_payout_mismatch';
        });
    }

    /**
     * S-10 — payout failed at the provider (bank account error): mark the
     * payout failed and release its settlement rows back to
     * `pending_payout` (the provider will roll them into a later payout).
     * The released-from trail is kept in row metadata — money never loses
     * its history.
     */
    private function applyPayoutFailed(PaymentProviderEvent $event): string
    {
        $connection = PaymentGatewayConnection::query()->findOrFail($event->connection_id);
        $snapshot = $this->payoutSnapshot($event);

        if ($snapshot === null) {
            return 'settlement_skipped_no_payout_reference';
        }

        $provider = $this->providerCode($connection);
        $payout = $this->upsertPayout($connection, $provider, (string) $snapshot['id'], $snapshot, GatewayPayoutStatus::Failed);

        return DB::transaction(function () use ($payout): string {
            $payout->update([
                'status' => GatewayPayoutStatus::Failed,
                'reconciled_at' => null,
            ]);

            $released = PaymentSettlement::whileReleasingReconciled(function () use ($payout): int {
                $count = 0;
                $rows = PaymentSettlement::query()->where('payout_id', $payout->id)->get();

                foreach ($rows as $row) {
                    $metadata = $row->metadata ?? [];
                    $metadata['released_from_payout_ids'] = array_values(array_unique(array_merge(
                        (array) ($metadata['released_from_payout_ids'] ?? []),
                        [(string) $payout->external_payout_id],
                    )));

                    $row->update([
                        'payout_id' => null,
                        'status' => SettlementStatus::PendingPayout,
                        'metadata' => $metadata,
                    ]);
                    $count++;
                }

                return $count;
            });

            return "settlement_payout_failed_released:{$released}";
        });
    }

    /**
     * S-13 — a balance transaction the webhook path never ingested. Known
     * types get their proper kind; anything else lands as kind=manual with
     * the raw provider type preserved for later reclassification, and the
     * payout can still reconcile.
     */
    private function backfillRowFromPayoutTransaction(
        PaymentGatewayConnection $connection,
        BalanceTransaction $transaction,
    ): PaymentSettlement {
        [$feeMinor, $feeTaxMinor] = $this->splitFeeAndTax($transaction);
        $type = (string) $transaction->type;

        $kind = match ($type) {
            'charge', 'payment' => SettlementKind::Payment,
            'refund', 'payment_refund' => SettlementKind::Refund,
            'stripe_fee' => SettlementKind::AccountFee,
            default => SettlementKind::Manual,
        };

        if ($kind === SettlementKind::Manual) {
            Log::channel('payment_orchestration')->warning('settlement_unknown_balance_txn_type', [
                'balance_transaction_id' => (string) $transaction->id,
                'raw_type' => $type,
                'connection_id' => (string) $connection->id,
            ]);
        }

        [$row] = $this->assembler->persistIdempotent($this->assembler->assemble(
            $connection,
            $kind,
            SettlementSource::Api,
            (string) $transaction->id,
            (int) $transaction->amount,
            $feeMinor,
            $feeTaxMinor,
            (int) $transaction->net,
            strtoupper((string) $transaction->currency),
            $this->timestamp($transaction->created ?? null),
            // Backfilled during payout listing without a local match — the
            // reconcile sweep may re-match it to a payment later (S-05).
            $kind === SettlementKind::Manual ? SettlementStatus::Orphan : SettlementStatus::PendingPayout,
            metadata: array_filter([
                'raw_type' => $type,
                'source_object' => is_string($transaction->source ?? null) ? $transaction->source : null,
                'backfilled_from_payout_listing' => true,
            ], static fn ($value) => $value !== null),
        ));

        return $row;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function upsertPayout(
        PaymentGatewayConnection $connection,
        string $provider,
        string $payoutId,
        array $snapshot,
        GatewayPayoutStatus $status,
    ): GatewayPayout {
        $existing = GatewayPayout::query()
            ->where('provider', $provider)
            ->where('external_payout_id', $payoutId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $netMinor = (int) ($snapshot['amount'] ?? 0);
        $arrival = $snapshot['arrival_date'] ?? null;

        try {
            return GatewayPayout::query()->create([
                'connection_id' => (string) $connection->id,
                'provider' => $provider,
                'external_payout_id' => $payoutId,
                'expected_arrival_date' => is_numeric($arrival)
                    ? CarbonImmutable::createFromTimestamp((int) $arrival, 'UTC')->toDateString()
                    : null,
                'paid_at' => $status === GatewayPayoutStatus::Paid ? now() : null,
                // Stripe's payout object carries the transferred (net) amount;
                // payout-level fees are separate balance transactions, so the
                // payout row itself reports fee 0.
                'gross_minor' => $netMinor,
                'fee_minor' => 0,
                'net_minor' => $netMinor,
                'currency' => strtoupper((string) ($snapshot['currency'] ?? '')),
                'status' => $status,
                'metadata' => [
                    'stripe_status' => $snapshot['status'] ?? null,
                ],
            ]);
        } catch (UniqueConstraintViolationException) {
            return GatewayPayout::query()
                ->where('provider', $provider)
                ->where('external_payout_id', $payoutId)
                ->firstOrFail();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payoutSnapshot(PaymentProviderEvent $event): ?array
    {
        $payload = is_array($event->redacted_payload) ? $event->redacted_payload : [];
        $snapshot = $payload['payout_snapshot'] ?? null;

        if (is_array($snapshot) && is_string($snapshot['id'] ?? null) && $snapshot['id'] !== '') {
            return $snapshot;
        }

        $payoutId = (string) ($event->provider_object_id ?? '');
        if (! str_starts_with($payoutId, 'po_')) {
            return null;
        }

        $connection = PaymentGatewayConnection::query()->findOrFail($event->connection_id);
        $payout = $this->client->retrievePayout($connection, $payoutId);

        return $payout->toArray();
    }

    /**
     * #2864 — chặn từ ĐẦU NGUỒN: tiền của hệ khác không được sinh hàng
     * `payment_settlements`.
     *
     * Tài khoản Stripe của quán dùng CHUNG với trang đặt món WooCommerce
     * riêng của họ, và Stripe gửi mọi sự kiện của tài khoản tới endpoint
     * Tempo. Đo 2026-08-14: **202 hàng `status='orphan'`, ¥366.643 gross,
     * ¥13.194 phí**, tăng ~35–50 hàng/ngày kể từ 2026-08-10 — toàn bộ là tiền
     * của trang kia. Một sổ đối soát cộng thêm doanh thu của hệ khác thì mọi
     * con số phái sinh từ nó đều sai, và `settlement_report_batches` mới có 0
     * hàng nên đây là lúc rẻ nhất để chặn.
     *
     * Phép phân biệt là `StripeIntentOrigin` — CHUNG với tầng webhook
     * (#2851), không phải bản chép thứ hai.
     *
     * **CHỈ `ForeignAccount` mới bị chặn.** `Unknown` (snapshot không mang
     * `metadata.order_id`) đi tiếp như cũ, cố ý: bỏ sót một khoản của hệ khác
     * chỉ là rác trong sổ, dọn được bằng một lượt xoá; bỏ sót một khoản của
     * TA là mất dấu tiền thật, mà tiền mất dấu thì không có đường quay lại.
     * Không chắc ⇒ coi là của ta.
     *
     * **Không áp cho `backfillRowFromPayoutTransaction()`.** Bản kê payout
     * liệt kê MỌI balance transaction của tài khoản, và rào S-12 đòi
     * Σ net hàng đính kèm == net payout. Lọc bớt ở đó sẽ làm mọi payout
     * mismatch vĩnh viễn — đúng nghĩa dựng một báo động giả thường trực.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function isForeignAccountMoney(array $snapshot, string $intentId, string $eventType): bool
    {
        $orderId = $snapshot['metadata']['order_id'] ?? null;

        if (StripeIntentOrigin::fromOrderIdMetadata($orderId) !== StripeIntentOrigin::ForeignAccount) {
            return false;
        }

        Log::channel('payment_orchestration')->debug('settlement_skipped_foreign_account', [
            'payment_intent' => $intentId,
            'event_type' => $eventType,
            'metadata_order_id' => (string) $orderId,
        ]);

        return true;
    }

    /**
     * S-16 — fee tax is read from fee_details entries of type `tax` when the
     * statement carries them; the remainder is the fee proper. JP card fees
     * have no tax entry ⇒ fee_tax 0 falls out of the data.
     *
     * @return array{0: int, 1: int} [fee_minor, fee_tax_minor]
     */
    private function splitFeeAndTax(BalanceTransaction $transaction): array
    {
        $totalFee = (int) $transaction->fee;
        $feeTax = 0;

        foreach (($transaction->fee_details ?? []) as $detail) {
            $type = is_array($detail) ? ($detail['type'] ?? null) : ($detail->type ?? null);
            $amount = is_array($detail) ? ($detail['amount'] ?? 0) : ($detail->amount ?? 0);

            if ($type === 'tax') {
                $feeTax += (int) $amount;
            }
        }

        return [$totalFee - $feeTax, $feeTax];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function chargeIdFromIntentSnapshot(array $intent): ?string
    {
        $latest = $intent['latest_charge'] ?? null;
        if (is_string($latest) && $latest !== '') {
            return $latest;
        }
        if (is_array($latest) && is_string($latest['id'] ?? null) && $latest['id'] !== '') {
            return $latest['id'];
        }

        $charge = $intent['charges']['data'][0] ?? null;
        if (is_array($charge) && is_string($charge['id'] ?? null) && $charge['id'] !== '') {
            return $charge['id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $dispute
     */
    private function disputeIntentId(array $dispute): string
    {
        $intent = $dispute['payment_intent'] ?? null;
        if (is_array($intent)) {
            $intent = $intent['id'] ?? null;
        }

        return is_string($intent) ? $intent : '';
    }

    private function timestamp(mixed $unix): ?CarbonImmutable
    {
        return is_numeric($unix) ? CarbonImmutable::createFromTimestamp((int) $unix, 'UTC') : null;
    }

    private function providerCode(PaymentGatewayConnection $connection): string
    {
        $connection->loadMissing('provider');
        $code = $connection->provider->code;

        return $code instanceof \BackedEnum ? (string) $code->value : (string) $code;
    }
}
