<?php

namespace App\Services\Payment\Observation;

use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use Illuminate\Support\Collection;

/**
 * Read-only ledger drift scan shared by `payments:check-ledger-drift` and
 * `payments:observation-report` (Plan 047 T4.9 / T7.4).
 */
final class LedgerDriftScanner
{
    private const MONEY_EPSILON = 0.005;

    /**
     * @return array{
     *     inspected: int,
     *     drift_count: int,
     *     gap_payments: int,
     *     findings: list<array{order_id: string, kind: string, expected: float, actual: float, status: string}>
     * }
     */
    public function scan(?string $organizationId = null, ?int $limit = null): array
    {
        $findings = [];
        $inspected = 0;
        $gapPayments = 0;

        $orders = CustomerOrder::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('id');

        $remaining = $limit;

        $orders->chunkById(500, function ($chunk) use (&$findings, &$inspected, &$gapPayments, &$remaining): bool {
            if ($remaining !== null) {
                $chunk = $chunk->take($remaining);
            }

            [$chunkFindings, $chunkGapPayments] = $this->inspectChunk($chunk);
            $findings = array_merge($findings, $chunkFindings);
            $gapPayments += $chunkGapPayments;
            $inspected += $chunk->count();

            if ($remaining !== null) {
                $remaining -= $chunk->count();

                return $remaining > 0;
            }

            return true;
        });

        return [
            'inspected' => $inspected,
            'drift_count' => count($findings),
            'gap_payments' => $gapPayments,
            'findings' => $findings,
        ];
    }

    /**
     * @param  Collection<int, CustomerOrder>  $chunk
     * @return array{0: list<array{order_id: string, kind: string, expected: float, actual: float, status: string}>, 1: int}
     */
    private function inspectChunk(Collection $chunk): array
    {
        $findings = [];
        $gapPayments = 0;
        $orderIds = $chunk->pluck('id')->all();

        if ($orderIds === []) {
            return [$findings, $gapPayments];
        }

        // #1801 — read through the MODEL, not `DB::table`.
        //
        // `order_payments` soft-deletes (`OrderPaymentBaseModel` uses
        // SoftDeletes), and the projection this scanner audits against —
        // `EloquentOrderPaymentLedgerReads::cachedTotalsForOrder()`, the very
        // thing that writes `paid_amount` — reads through `OrderPayment::query()`
        // and therefore excludes deleted rows. `DB::table` applies no model
        // scope, so the scanner used to compare a sum that INCLUDED deleted rows
        // against a `paid_amount` that did not, and reported drift equal to the
        // deleted total on a perfectly healthy order. A false alarm is not
        // harmless here: this feeds `payments:observation-report --strict`,
        // which gates cron/CI.
        //
        // Going through the model rather than bolting on `whereNull('deleted_at')`
        // is the point: an auditor must be scoped IDENTICALLY to the projection
        // it audits, and inheriting the same global scopes makes that true by
        // construction instead of by remembering. The next scope added to the
        // model reaches both sides at once.
        $netByOrder = OrderPayment::query()
            ->whereIn('customer_order_id', $orderIds)
            ->whereNull('metadata->settles_payment_id')
            ->whereIn('status', [
                PaymentStatusEnum::Succeeded->value,
                PaymentStatusEnum::Refunded->value,
            ])
            ->groupBy('customer_order_id')
            ->selectRaw('customer_order_id, COALESCE(SUM(amount), 0) as net')
            ->pluck('net', 'customer_order_id');

        $gapByOrder = OrderPayment::query()
            ->whereIn('customer_order_id', $orderIds)
            ->whereNull('refund_of_id')
            ->whereNull('till_session_id')
            ->where('status', PaymentStatusEnum::Succeeded->value)
            ->groupBy('customer_order_id')
            ->selectRaw('customer_order_id, COUNT(*) as cnt')
            ->pluck('cnt', 'customer_order_id');

        foreach ($chunk as $order) {
            $net = (float) ($netByOrder[$order->id] ?? 0.0);
            $paid = (float) $order->paid_amount;
            $total = (float) $order->total_amount;
            $status = $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status;

            $gapPayments += (int) ($gapByOrder[$order->id] ?? 0);

            if (abs($paid - $net) > self::MONEY_EPSILON) {
                $findings[] = $this->finding($order->id, 'paid_amount_drift', $net, $paid, $status);
            }

            if ($status === CustomerOrderStatusEnum::Closed->value && $net + self::MONEY_EPSILON < $total) {
                $findings[] = $this->finding($order->id, 'closed_underpaid', $total, $net, $status);
            }

            if ($net < -self::MONEY_EPSILON) {
                $findings[] = $this->finding($order->id, 'negative_net', 0.0, $net, $status);
            }
        }

        return [$findings, $gapPayments];
    }

    /**
     * @return array{order_id: string, kind: string, expected: float, actual: float, status: string}
     */
    private function finding(string $orderId, string $kind, float $expected, float $actual, string $status): array
    {
        return [
            'order_id' => $orderId,
            'kind' => $kind,
            'expected' => round($expected, 2),
            'actual' => round($actual, 2),
            'status' => $status,
        ];
    }
}
