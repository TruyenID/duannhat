<?php

namespace App\Services\Payment\Settlement;

use App\Models\PaymentSettlement;
use App\Services\Payment\Settlement\Enums\SettlementStatus;

/**
 * Plan-050 M4 (T4.2, #1155) — "how much money is still sitting at the
 * gateway" per connection, bucketed by days pending.
 *
 * Age is DAYS ELAPSED between `provider_settled_at` (the gateway's own
 * settlement timestamp, stored UTC) and now — a pure duration, so the
 * result is identical in any operator timezone (S-22 / #1091: no
 * BusinessClock, no local calendar).
 *
 * Bucket boundaries come from `payments.settlement.aging_buckets`
 * (upper-inclusive day edges; a final open-ended bucket is appended).
 * The per-provider alert threshold (`aging_alert_days`) is configuration —
 * Stripe pays out in days, PayPay in monthly cycles (G4: no hardcoding).
 *
 * ESTIMATE CONTRACT (G1): totals are Σ payment_settlements.net_minor only —
 * the L1 estimate column is never read here.
 */
final class SettlementAgingReportService
{
    /**
     * @return list<array{
     *     connection_id: string,
     *     provider: string,
     *     currency: string,
     *     total_net_minor: int,
     *     row_count: int,
     *     oldest_age_days: int|null,
     *     over_threshold: bool,
     *     buckets: array<string, array{net_minor: int, row_count: int}>,
     * }>
     */
    public function pendingPayoutAging(?string $connectionId = null): array
    {
        /** @var list<int> $edges */
        $edges = array_values(array_map('intval', (array) config('payments.settlement.aging_buckets', [3, 7, 14, 30])));
        sort($edges);

        /** @var array<string, int> $alertDays */
        $alertDays = (array) config('payments.settlement.aging_alert_days', []);

        $rows = PaymentSettlement::query()
            ->where('status', SettlementStatus::PendingPayout->value)
            ->when($connectionId !== null, fn ($query) => $query->where('connection_id', $connectionId))
            ->get();

        /** @var array<string, array<string, mixed>> $report */
        $report = [];

        foreach ($rows as $row) {
            $key = $row->connection_id.'|'.$row->currency;

            if (! isset($report[$key])) {
                $report[$key] = [
                    'connection_id' => (string) $row->connection_id,
                    'provider' => (string) $row->provider,
                    'currency' => (string) $row->currency,
                    'total_net_minor' => 0,
                    'row_count' => 0,
                    'oldest_age_days' => null,
                    'over_threshold' => false,
                    'buckets' => $this->emptyBuckets($edges),
                ];
            }

            $settledAt = $row->provider_settled_at ?? $row->created_at;
            $ageDays = $settledAt === null ? 0 : (int) $settledAt->diffInDays(now());
            $bucket = $this->bucketLabel($edges, $ageDays);

            $report[$key]['total_net_minor'] += (int) $row->net_minor;
            $report[$key]['row_count']++;
            $report[$key]['buckets'][$bucket]['net_minor'] += (int) $row->net_minor;
            $report[$key]['buckets'][$bucket]['row_count']++;

            if ($report[$key]['oldest_age_days'] === null || $ageDays > $report[$key]['oldest_age_days']) {
                $report[$key]['oldest_age_days'] = $ageDays;
            }

            $threshold = $alertDays[(string) $row->provider] ?? null;
            if ($threshold !== null && $ageDays > (int) $threshold) {
                $report[$key]['over_threshold'] = true;
            }
        }

        return array_values($report);
    }

    /**
     * @param  list<int>  $edges
     * @return array<string, array{net_minor: int, row_count: int}>
     */
    private function emptyBuckets(array $edges): array
    {
        $buckets = [];
        $previous = 0;

        foreach ($edges as $edge) {
            $buckets["{$previous}-{$edge}d"] = ['net_minor' => 0, 'row_count' => 0];
            $previous = $edge + 1;
        }

        $buckets["{$previous}d+"] = ['net_minor' => 0, 'row_count' => 0];

        return $buckets;
    }

    /**
     * @param  list<int>  $edges
     */
    private function bucketLabel(array $edges, int $ageDays): string
    {
        $previous = 0;

        foreach ($edges as $edge) {
            if ($ageDays <= $edge) {
                return "{$previous}-{$edge}d";
            }
            $previous = $edge + 1;
        }

        return "{$previous}d+";
    }
}
