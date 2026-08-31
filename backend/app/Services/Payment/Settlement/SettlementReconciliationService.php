<?php

namespace App\Services\Payment\Settlement;

use App\Models\GatewayPayout;
use App\Models\PaymentAttempt;
use App\Models\PaymentSettlement;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\Payment\Settlement\Enums\GatewayPayoutStatus;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use Illuminate\Support\Facades\Log;

/**
 * Plan-050 M4 (T4.1, #1155) — two-direction settlement reconciliation.
 *
 * Direction A (us → gateway): succeeded gateway payment attempts older than
 * the provider's configured window with NO settlement row — "the gateway
 * never told us about this money" (also the self-healing detector for a
 * missing webhook subscription, G6).
 *
 * Direction B (gateway → us): orphan settlement rows (gateway has money we
 * can't match) and mismatch payouts (Σ net ≠ payout net, S-12).
 *
 * Before reporting, orphans are RE-MATCHED (S-05/S-19): a payment that
 * synced late (offline replay #1092) claims its orphan row automatically,
 * so the alert only fires for rows that stay unmatched. The sweep is
 * idempotent — a second immediate invocation changes no state (S-23).
 *
 * Only providers with a configured `reconcile_after_days` window take part:
 * `internal` (cash & friends) has no gateway statement to reconcile against.
 *
 * ESTIMATE CONTRACT (G1): this service reads payment_settlements and
 * gateway_payouts only — never the L1 estimate column on payment_attempts.
 */
final class SettlementReconciliationService
{
    /**
     * @return array{
     *     unsettled_payments: list<array<string, mixed>>,
     *     rematched: list<array<string, mixed>>,
     *     orphans: list<array<string, mixed>>,
     *     mismatch_payouts: list<array<string, mixed>>,
     *     mismatch_rows: list<array<string, mixed>>,
     * }
     */
    public function reconcile(?string $connectionId = null, bool $dryRun = false): array
    {
        $rematched = $this->rematchOrphans($connectionId, $dryRun);

        return [
            'unsettled_payments' => $this->unsettledPayments($connectionId),
            'rematched' => $rematched,
            'orphans' => $this->openOrphans($connectionId),
            'mismatch_payouts' => $this->mismatchPayouts($connectionId),
            'mismatch_rows' => $this->mismatchRows($connectionId),
        ];
    }

    /**
     * Direction A — succeeded attempts past the provider window with no
     * settlement row.
     *
     * @return list<array<string, mixed>>
     */
    private function unsettledPayments(?string $connectionId): array
    {
        /** @var array<string, int> $windows */
        $windows = (array) config('payments.settlement.reconcile_after_days', []);
        if ($windows === []) {
            return [];
        }

        $results = [];

        foreach ($windows as $provider => $days) {
            $attempts = PaymentAttempt::query()
                ->where('provider', $provider)
                ->where('state', PaymentAttemptStateEnum::Succeeded->value)
                ->where('finalized_at', '<=', now()->subDays((int) $days))
                ->when($connectionId !== null, fn ($query) => $query->where('connection_id', $connectionId))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('payment_settlements')
                        ->whereColumn('payment_settlements.payment_attempt_id', 'payment_attempts.id');
                })
                ->orderBy('finalized_at')
                ->limit(500)
                ->get();

            foreach ($attempts as $attempt) {
                $results[] = [
                    'attempt_id' => (string) $attempt->id,
                    'order_id' => (string) $attempt->customer_order_id,
                    'connection_id' => (string) $attempt->connection_id,
                    'provider' => $attempt->provider instanceof \BackedEnum
                        ? (string) $attempt->provider->value
                        : (string) $attempt->provider,
                    'amount_minor' => (int) $attempt->amount_minor,
                    'currency' => (string) $attempt->currency,
                    'finalized_at' => $attempt->finalized_at?->toIso8601String(),
                    'age_days' => $attempt->finalized_at === null
                        ? null
                        : (int) $attempt->finalized_at->diffInDays(now()),
                ];
            }
        }

        return $results;
    }

    /**
     * S-05 / S-19 — orphan rows whose payment has since arrived re-match
     * automatically (before any alert). Mutates only when not a dry run.
     *
     * @return list<array<string, mixed>>
     */
    private function rematchOrphans(?string $connectionId, bool $dryRun): array
    {
        $orphans = PaymentSettlement::query()
            ->where('status', SettlementStatus::Orphan->value)
            ->when($connectionId !== null, fn ($query) => $query->where('connection_id', $connectionId))
            ->get();

        $rematched = [];

        foreach ($orphans as $row) {
            $metadata = $row->metadata ?? [];
            $providerObjectId = $metadata['provider_object_id'] ?? null;

            if (! is_string($providerObjectId) || $providerObjectId === '') {
                continue;
            }

            $attempt = PaymentAttempt::query()
                ->where('connection_id', $row->connection_id)
                ->where('provider_object_id', $providerObjectId)
                ->first();

            if ($attempt === null) {
                continue;
            }

            if (! $dryRun) {
                $payoutReconciled = $row->payout_id !== null
                    && GatewayPayout::query()
                        ->where('id', $row->payout_id)
                        ->whereNotNull('reconciled_at')
                        ->exists();

                $row->update([
                    'payment_attempt_id' => $attempt->id,
                    'status' => $payoutReconciled ? SettlementStatus::Reconciled : SettlementStatus::PendingPayout,
                    'metadata' => array_merge($metadata, [
                        'rematched_at' => now()->toIso8601String(),
                    ]),
                ]);

                Log::channel('payment_orchestration')->info('settlement_orphan_rematched', [
                    'settlement_id' => $row->id,
                    'payment_attempt_id' => $attempt->id,
                ]);
            }

            $rematched[] = [
                'settlement_id' => (string) $row->id,
                'external_ref' => (string) $row->external_ref,
                'attempt_id' => (string) $attempt->id,
                'net_minor' => (int) $row->net_minor,
                'currency' => (string) $row->currency,
                'dry_run' => $dryRun,
            ];
        }

        return $rematched;
    }

    /**
     * Direction B — orphans that stayed unmatched (kept forever, S-05).
     *
     * @return list<array<string, mixed>>
     */
    private function openOrphans(?string $connectionId): array
    {
        return PaymentSettlement::query()
            ->where('status', SettlementStatus::Orphan->value)
            ->when($connectionId !== null, fn ($query) => $query->where('connection_id', $connectionId))
            ->orderBy('provider_settled_at')
            ->limit(500)
            ->get()
            ->map(fn (PaymentSettlement $row): array => [
                'settlement_id' => (string) $row->id,
                'connection_id' => (string) $row->connection_id,
                'provider' => (string) $row->provider,
                'kind' => $row->kind->value,
                'external_ref' => (string) $row->external_ref,
                'net_minor' => (int) $row->net_minor,
                'currency' => (string) $row->currency,
                'provider_settled_at' => $row->provider_settled_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Direction B — payouts whose Σ net check failed (S-12).
     *
     * @return list<array<string, mixed>>
     */
    private function mismatchPayouts(?string $connectionId): array
    {
        return GatewayPayout::query()
            ->where('status', GatewayPayoutStatus::Mismatch->value)
            ->when($connectionId !== null, fn ($query) => $query->where('connection_id', $connectionId))
            ->orderBy('paid_at')
            ->limit(500)
            ->get()
            ->map(fn (GatewayPayout $payout): array => [
                'payout_id' => (string) $payout->id,
                'connection_id' => (string) $payout->connection_id,
                'provider' => (string) $payout->provider,
                'external_payout_id' => (string) $payout->external_payout_id,
                'net_minor' => (int) $payout->net_minor,
                'currency' => (string) $payout->currency,
                'mismatch' => $payout->metadata['reconciliation_mismatch'] ?? null,
            ])
            ->all();
    }

    /**
     * Direction B — rows individually flagged mismatch (currency S-17 etc.).
     *
     * @return list<array<string, mixed>>
     */
    private function mismatchRows(?string $connectionId): array
    {
        return PaymentSettlement::query()
            ->where('status', SettlementStatus::Mismatch->value)
            ->when($connectionId !== null, fn ($query) => $query->where('connection_id', $connectionId))
            ->orderBy('provider_settled_at')
            ->limit(500)
            ->get()
            ->map(fn (PaymentSettlement $row): array => [
                'settlement_id' => (string) $row->id,
                'connection_id' => (string) $row->connection_id,
                'provider' => (string) $row->provider,
                'kind' => $row->kind->value,
                'external_ref' => (string) $row->external_ref,
                'net_minor' => (int) $row->net_minor,
                'currency' => (string) $row->currency,
                'metadata' => $row->metadata,
            ])
            ->all();
    }
}
