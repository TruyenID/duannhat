<?php

namespace App\Services\Pos;

use App\Services\Customer\OrderTaxBreakdownAggregator;
use App\Services\Menu\Contracts\ShopMenuSections;
use App\Services\Payment\Contracts\BranchPaymentTotals;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates closed-order revenue for the POS revenue report screen.
 *
 * Single entry point: summary() returns KPIs, time-series, weekday averages
 * and a payment-method breakdown for a window bounded by [from, to] at the
 * requested granularity (day or month). The same response shape is mirrored
 * by the workstation LAN handler so pos-web treats them interchangeably.
 */
class PosRevenueService
{
    /** #1622 — cổng đọc MỤC MENU của cửa hàng, do Catalog công bố. */

    /** #1622 — cổng TIỀN ĐÃ THU của Payments (đã ròng theo #1123/#1125). */
    private function payments(): BranchPaymentTotals
    {
        return app(BranchPaymentTotals::class);
    }

    private function sections(): ShopMenuSections
    {
        return app(ShopMenuSections::class);
    }

    public const GRANULARITY_DAY = 'day';

    public const GRANULARITY_MONTH = 'month';

    public const GRANULARITY_YEAR = 'year';

    public const PRODUCT_LEVEL_PRODUCT = 'product';

    public const PRODUCT_LEVEL_SKU = 'sku';

    public const PRODUCT_SORT_REVENUE = 'revenue';

    public const PRODUCT_SORT_QUANTITY = 'quantity';

    public const PRODUCT_SORT_SHARE = 'share';

    public function __construct(
        private readonly OrderTaxBreakdownAggregator $taxBreakdown = new OrderTaxBreakdownAggregator,
    ) {}

    /**
     * @return array{
     *   granularity: string,
     *   from: string,
     *   to: string,
     *   kpis: array{
     *     revenue: int,
     *     orders: int,
     *     guests: int,
     *     avg_per_guest: int,
     *     compare_revenue: int,
     *     delta_pct: int,
     *     net: int,
     *     gross: int,
     *     tax: int,
     *     by_tax_rate: list<array{rate: float, taxable: int, tax: int}>,
     *   },
     *   series: list<array{period: string, revenue: int, orders: int, guests: int}>,
     *   by_weekday: list<array{weekday: int, avg_revenue: int, total_revenue: int, sample_days: int}>,
     *   by_payment_method: list<array{method_id: ?string, code: ?string, name: ?string, amount: int, share_pct: float}>,
     *   generated_at: string,
     * }
     */
    public function summary(
        string $branchId,
        string $organizationId,
        string $granularity,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $granularity = match ($granularity) {
            self::GRANULARITY_YEAR => self::GRANULARITY_YEAR,
            self::GRANULARITY_MONTH => self::GRANULARITY_MONTH,
            default => self::GRANULARITY_DAY,
        };

        // Same-length compare window immediately preceding [from, to].
        $windowDays = $from->diffInDays($to) + 1;
        $prevTo = $from->subDay()->endOfDay();
        $prevFrom = $prevTo->subDays($windowDays - 1)->startOfDay();

        $kpis = $this->kpis($branchId, $organizationId, $from->startOfDay(), $to->endOfDay(), $prevFrom, $prevTo);

        $series = $this->series($branchId, $organizationId, $granularity, $from->startOfDay(), $to->endOfDay());

        $byWeekday = $granularity === self::GRANULARITY_DAY
            ? $this->byWeekday($branchId, $organizationId, $from->startOfDay(), $to->endOfDay())
            : [];

        $byPaymentMethod = $this->byPaymentMethod($branchId, $organizationId, $from->startOfDay(), $to->endOfDay());

        return [
            'granularity' => $granularity,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kpis' => $kpis,
            'series' => $series,
            'by_weekday' => $byWeekday,
            'by_payment_method' => $byPaymentMethod,
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @return array{revenue: int, orders: int, guests: int, avg_per_guest: int, compare_revenue: int, delta_pct: int, net: int, gross: int, tax: int, by_tax_rate: list<array{rate: float, taxable: int, tax: int}>}
     */
    private function kpis(
        string $branchId,
        string $organizationId,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        \DateTimeInterface $prevFrom,
        \DateTimeInterface $prevTo,
    ): array {
        $current = $this->aggregateTotals($branchId, $organizationId, $from, $to);
        $previous = $this->aggregateTotals($branchId, $organizationId, $prevFrom, $prevTo);

        $avgPerGuest = $current['guests'] > 0
            ? (int) round($current['revenue'] / $current['guests'])
            : 0;

        // plan-043 T4.6 (§3.13) — expose 税抜 (net) / 税込 (gross) / 消費税 (tax)
        // + per-rate breakdown so the summary tab reconciles with the
        // by-product tab: `revenue`/`gross` = Σ total_amount (tax-inclusive,
        // unchanged for BC), `net` = the tax-exclusive figure the by-product
        // tab reports, `tax` bridges them.
        $breakdown = $this->taxBreakdownForWindow($branchId, $organizationId, $from, $to);

        return [
            'revenue' => $current['revenue'],
            'orders' => $current['orders'],
            'guests' => $current['guests'],
            'avg_per_guest' => $avgPerGuest,
            'compare_revenue' => $previous['revenue'],
            'delta_pct' => $this->deltaPercent($current['revenue'], $previous['revenue']),
            'net' => (int) round($breakdown['net']),
            'gross' => $current['revenue'],
            'tax' => (int) round($breakdown['tax']),
            'by_tax_rate' => $this->intBreakdownRows($breakdown['by_rate']),
        ];
    }

    /**
     * @return array{revenue: int, orders: int, guests: int}
     */
    private function aggregateTotals(
        string $branchId,
        string $organizationId,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): array {
        $row = DB::table('customer_orders')
            ->where('branch_id', $branchId)
            ->where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(guest_count), 0) as guests')
            ->first();

        $gross = (float) DB::table('customer_orders')
            ->where('branch_id', $branchId)
            ->where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('deleted_at')
            ->sum('total_amount');

        // Revenue must be NET of refunds. The gross above is the face value of
        // closed orders and ignored refunds entirely, so a sale whose money was
        // handed back in full still reported its full total — and contradicted the
        // by_payment_method panel, which is derived from the ledger. Refund rows
        // carry a negative amount, so adding them subtracts the money returned.
        //
        // #1123 — dispute re-credits ride the same netting: a chargeback
        // withdrawal is a refund_of_id contra (subtracts here like any refund),
        // and WINNING the dispute appends a positive `dispute_kind=reinstatement`
        // row with no refund_of_id. Without it a won dispute kept subtracting —
        // KPI said 0 while by_payment_method correctly said the money is back.
        $refunds = $this->payments()->reversalTotal($branchId, $organizationId, $from, $to);

        return [
            'revenue' => (int) round($gross + $refunds),
            'orders' => (int) ($row->orders ?? 0),
            'guests' => (int) ($row->guests ?? 0),
        ];
    }

    /**
     * Per-rate consumption-tax breakdown over the closed orders in the window,
     * derived from the frozen per-line snapshots (reuses the shared aggregator
     * — never recomputes tax math).
     *
     * @return array{net: float, tax: float, gross: float, by_rate: list<array{rate: float, taxable: float, tax: float}>}
     */
    private function taxBreakdownForWindow(
        string $branchId,
        string $organizationId,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): array {
        $orderIds = DB::table('customer_orders')
            ->where('branch_id', $branchId)
            ->where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('deleted_at')
            ->pluck('id');

        return $this->taxBreakdown->forOrders($orderIds);
    }

    /**
     * Cast the aggregator's float per-rate rows to integer money (yen) for the
     * revenue-report wire shape.
     *
     * @param  list<array{rate: float, taxable: float, tax: float}>  $rows
     * @return list<array{rate: float, taxable: int, tax: int}>
     */
    private function intBreakdownRows(array $rows): array
    {
        return array_map(fn (array $row) => [
            'rate' => $row['rate'],
            'taxable' => (int) round($row['taxable']),
            'tax' => (int) round($row['tax']),
        ], $rows);
    }

    /**
     * @return list<array{period: string, revenue: int, orders: int, guests: int}>
     */
    private function series(
        string $branchId,
        string $organizationId,
        string $granularity,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): array {
        $periodFormat = match ($granularity) {
            self::GRANULARITY_YEAR => '%Y',
            self::GRANULARITY_MONTH => '%Y-%m',
            default => '%Y-%m-%d',
        };
        $period = $this->dateFmt('created_at', $periodFormat);

        $rows = DB::table('customer_orders')
            ->where('branch_id', $branchId)
            ->where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('deleted_at')
            ->selectRaw("{$period} as period, SUM(total_amount) as revenue, COUNT(*) as orders, COALESCE(SUM(guest_count), 0) as guests")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        // Backfill empty buckets so chart always shows the full window
        $series = [];
        $cursor = match ($granularity) {
            self::GRANULARITY_YEAR => $from->copy()->startOfYear(),
            self::GRANULARITY_MONTH => $from->copy()->startOfMonth(),
            default => $from->copy()->startOfDay(),
        };
        $end = match ($granularity) {
            self::GRANULARITY_YEAR => $to->copy()->startOfYear(),
            self::GRANULARITY_MONTH => $to->copy()->startOfMonth(),
            default => $to->copy()->startOfDay(),
        };

        while ($cursor->lte($end)) {
            $key = match ($granularity) {
                self::GRANULARITY_YEAR => $cursor->format('Y'),
                self::GRANULARITY_MONTH => $cursor->format('Y-m'),
                default => $cursor->format('Y-m-d'),
            };
            $row = $rows->get($key);
            $series[] = [
                'period' => $key,
                'revenue' => (int) ($row->revenue ?? 0),
                'orders' => (int) ($row->orders ?? 0),
                'guests' => (int) ($row->guests ?? 0),
            ];
            $cursor = match ($granularity) {
                self::GRANULARITY_YEAR => $cursor->copy()->addYear(),
                self::GRANULARITY_MONTH => $cursor->copy()->addMonth(),
                default => $cursor->copy()->addDay(),
            };
        }

        return $series;
    }

    /**
     * @return list<array{weekday: int, avg_revenue: int, total_revenue: int, sample_days: int}>
     */
    private function byWeekday(
        string $branchId,
        string $organizationId,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): array {
        $dayKey = $this->dateFmt('created_at', '%Y-%m-%d');
        $weekday = $this->weekdayExpr('created_at');

        $rows = DB::table('customer_orders')
            ->where('branch_id', $branchId)
            ->where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('deleted_at')
            ->selectRaw("{$weekday} as weekday, {$dayKey} as day, SUM(total_amount) as revenue")
            ->groupBy('weekday', 'day')
            ->get();

        $byWeekday = [];
        foreach ($rows as $r) {
            $w = (int) $r->weekday;
            if (! isset($byWeekday[$w])) {
                $byWeekday[$w] = ['total' => 0, 'days' => 0];
            }
            $byWeekday[$w]['total'] += (int) $r->revenue;
            $byWeekday[$w]['days'] += 1;
        }

        $out = [];
        for ($w = 0; $w < 7; $w++) {
            $bucket = $byWeekday[$w] ?? ['total' => 0, 'days' => 0];
            $out[] = [
                'weekday' => $w,
                'avg_revenue' => $bucket['days'] > 0 ? (int) round($bucket['total'] / $bucket['days']) : 0,
                'total_revenue' => $bucket['total'],
                'sample_days' => $bucket['days'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{method_id: ?string, code: ?string, name: ?string, amount: int, share_pct: float}>
     */
    private function byPaymentMethod(
        string $branchId,
        string $organizationId,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): array {
        $rows = $this->payments()->netByPaymentMethod($branchId, $organizationId, $from, $to);

        $total = array_sum(array_map(fn (array $r): int => $r['amount'], $rows));

        return array_map(fn (array $r): array => [
            'method_id' => $r['method_id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'amount' => $r['amount'],
            'share_pct' => $total > 0 ? round($r['amount'] * 100 / $total, 1) : 0.0,
        ], $rows);
    }

    private function deltaPercent(float $current, float $previous): int
    {
        if ($previous == 0.0) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    /**
     * Void (cancellation) analytics — two NON-overlapping lenses so nothing is
     * double-counted:
     *   - order voids : wholly-voided orders. total_amount is zeroed on void, so
     *     "value" is the cancelled order's item subtotals. Reasons from
     *     customer_orders.void_reason.
     *   - item voids  : per-item voids on orders that were NOT wholly voided
     *     (customer_order_items.status='voided' AND parent status != 'voided').
     * Timing uses COALESCE(voided_at, created_at) so a void with an unstamped
     * voided_at still lands in its window. Mirrors the workstation LAN shape.
     */
    public function voids(
        string $branchId,
        string $organizationId,
        string $granularity,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $granularity = match ($granularity) {
            self::GRANULARITY_YEAR => self::GRANULARITY_YEAR,
            self::GRANULARITY_MONTH => self::GRANULARITY_MONTH,
            default => self::GRANULARITY_DAY,
        };
        $f = $from->startOfDay();
        $t = $to->endOfDay();

        return [
            'granularity' => $granularity,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kpis' => $this->voidKpis($branchId, $organizationId, $f, $t),
            'series' => $this->voidSeries($branchId, $organizationId, $granularity, $f, $t),
            'order_reasons' => $this->voidReasons($branchId, $organizationId, $f, $t, false),
            'item_reasons' => $this->voidReasons($branchId, $organizationId, $f, $t, true),
            'top_items' => $this->voidTopItems($branchId, $organizationId, $f, $t),
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @return array{order_voids: int, order_void_value: int, item_voids: int, item_void_value: int, order_void_rate_pct: float}
     */
    private function voidKpis(string $branchId, string $organizationId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $orderVoids = (int) DB::table('customer_orders')
            ->where('branch_id', $branchId)->where('organization_id', $organizationId)
            ->where('status', 'voided')->whereNull('deleted_at')
            ->whereRaw('COALESCE(voided_at, created_at) BETWEEN ? AND ?', [$from, $to])
            ->count();

        $closed = (int) DB::table('customer_orders')
            ->where('branch_id', $branchId)->where('organization_id', $organizationId)
            ->where('status', 'closed')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $orderVoidValue = (int) DB::table('customer_order_items as coi')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->where('co.branch_id', $branchId)->where('co.organization_id', $organizationId)
            ->where('co.status', 'voided')->whereNull('co.deleted_at')
            ->whereRaw('COALESCE(co.voided_at, co.created_at) BETWEEN ? AND ?', [$from, $to])
            ->sum('coi.subtotal');

        $item = DB::table('customer_order_items as coi')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->where('co.branch_id', $branchId)->where('co.organization_id', $organizationId)
            ->where('coi.status', 'voided')->where('co.status', '!=', 'voided')->whereNull('co.deleted_at')
            ->whereRaw('COALESCE(coi.voided_at, coi.created_at) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(coi.subtotal), 0) as v')
            ->first();

        $rate = ($orderVoids + $closed) > 0
            ? round($orderVoids * 100 / ($orderVoids + $closed), 1)
            : 0.0;

        return [
            'order_voids' => $orderVoids,
            'order_void_value' => $orderVoidValue,
            'item_voids' => (int) ($item->c ?? 0),
            'item_void_value' => (int) ($item->v ?? 0),
            'order_void_rate_pct' => $rate,
        ];
    }

    /**
     * @return list<array{period: string, order_voids: int, item_voids: int, void_value: int}>
     */
    private function voidSeries(string $branchId, string $organizationId, string $granularity, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $periodFormat = match ($granularity) {
            self::GRANULARITY_YEAR => '%Y',
            self::GRANULARITY_MONTH => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $orderPeriod = $this->dateFmt('COALESCE(co.voided_at, co.created_at)', $periodFormat);
        $orderRows = DB::table('customer_orders as co')
            ->leftJoin('customer_order_items as coi', 'coi.customer_order_id', '=', 'co.id')
            ->where('co.branch_id', $branchId)->where('co.organization_id', $organizationId)
            ->where('co.status', 'voided')->whereNull('co.deleted_at')
            ->whereRaw('COALESCE(co.voided_at, co.created_at) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw("{$orderPeriod} as period, COUNT(DISTINCT co.id) as c, COALESCE(SUM(coi.subtotal), 0) as v")
            ->groupBy('period')->get()->keyBy('period');

        $itemPeriod = $this->dateFmt('COALESCE(coi.voided_at, coi.created_at)', $periodFormat);
        $itemRows = DB::table('customer_order_items as coi')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->where('co.branch_id', $branchId)->where('co.organization_id', $organizationId)
            ->where('coi.status', 'voided')->where('co.status', '!=', 'voided')->whereNull('co.deleted_at')
            ->whereRaw('COALESCE(coi.voided_at, coi.created_at) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw("{$itemPeriod} as period, COUNT(*) as c, COALESCE(SUM(coi.subtotal), 0) as v")
            ->groupBy('period')->get()->keyBy('period');

        $out = [];
        $cursor = match ($granularity) {
            self::GRANULARITY_YEAR => $from->copy()->startOfYear(),
            self::GRANULARITY_MONTH => $from->copy()->startOfMonth(),
            default => $from->copy()->startOfDay(),
        };
        $end = match ($granularity) {
            self::GRANULARITY_YEAR => $to->copy()->startOfYear(),
            self::GRANULARITY_MONTH => $to->copy()->startOfMonth(),
            default => $to->copy()->startOfDay(),
        };
        while ($cursor->lte($end)) {
            $key = match ($granularity) {
                self::GRANULARITY_YEAR => $cursor->format('Y'),
                self::GRANULARITY_MONTH => $cursor->format('Y-m'),
                default => $cursor->format('Y-m-d'),
            };
            $o = $orderRows->get($key);
            $i = $itemRows->get($key);
            $out[] = [
                'period' => $key,
                'order_voids' => (int) ($o->c ?? 0),
                'item_voids' => (int) ($i->c ?? 0),
                'void_value' => (int) ($o->v ?? 0) + (int) ($i->v ?? 0),
            ];
            $cursor = match ($granularity) {
                self::GRANULARITY_YEAR => $cursor->copy()->addYear(),
                self::GRANULARITY_MONTH => $cursor->copy()->addMonth(),
                default => $cursor->copy()->addDay(),
            };
        }

        return $out;
    }

    /**
     * @return list<array{reason: string, count: int, value: int}>
     */
    private function voidReasons(string $branchId, string $organizationId, \DateTimeInterface $from, \DateTimeInterface $to, bool $item): array
    {
        if ($item) {
            $rows = DB::table('customer_order_items as coi')
                ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
                ->where('co.branch_id', $branchId)->where('co.organization_id', $organizationId)
                ->where('coi.status', 'voided')->where('co.status', '!=', 'voided')->whereNull('co.deleted_at')
                ->whereRaw('COALESCE(coi.voided_at, coi.created_at) BETWEEN ? AND ?', [$from, $to])
                ->selectRaw("COALESCE(NULLIF(TRIM(coi.void_reason), ''), '') as reason, COUNT(*) as c, COALESCE(SUM(coi.subtotal), 0) as v")
                ->groupBy('reason')->orderByDesc('c')->get();
        } else {
            $rows = DB::table('customer_orders as co')
                ->leftJoin('customer_order_items as coi', 'coi.customer_order_id', '=', 'co.id')
                ->where('co.branch_id', $branchId)->where('co.organization_id', $organizationId)
                ->where('co.status', 'voided')->whereNull('co.deleted_at')
                ->whereRaw('COALESCE(co.voided_at, co.created_at) BETWEEN ? AND ?', [$from, $to])
                ->selectRaw("COALESCE(NULLIF(TRIM(co.void_reason), ''), '') as reason, COUNT(DISTINCT co.id) as c, COALESCE(SUM(coi.subtotal), 0) as v")
                ->groupBy('reason')->orderByDesc('c')->get();
        }

        return $rows->map(fn ($r) => [
            'reason' => (string) $r->reason,
            'count' => (int) $r->c,
            'value' => (int) $r->v,
        ])->all();
    }

    /**
     * @return list<array{name: string, variant: string, count: int, value: int}>
     */
    private function voidTopItems(string $branchId, string $organizationId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $query = DB::table('customer_order_items as coi')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->leftJoin('product_skus as ps', 'ps.id', '=', 'coi.product_sku_id')
            ->leftJoin('products as p', 'p.id', '=', 'ps.product_id')
            ->where('co.branch_id', $branchId)->where('co.organization_id', $organizationId)
            ->where('coi.status', 'voided')->where('co.status', '!=', 'voided')->whereNull('co.deleted_at')
            ->whereRaw('COALESCE(coi.voided_at, coi.created_at) BETWEEN ? AND ?', [$from, $to]);

        // Follow the operator's pos-web language for the product + variant name.
        $this->joinTranslations($query, 'product_translations', 'product_id', 'p.id', 'p_t');
        $this->joinTranslations($query, 'product_sku_translations', 'product_sku_id', 'ps.id', 'ps_t');
        $name = $this->translatedNameExpr('p_t', 'p.name');
        $variant = $this->translatedNameExpr('ps_t', 'ps.name');

        $rows = $query
            ->selectRaw("MAX(COALESCE({$name}, coi.product_sku_id)) as name, MAX(COALESCE({$variant}, '')) as variant, COUNT(*) as c, COALESCE(SUM(coi.subtotal), 0) as v")
            ->groupBy('coi.product_sku_id')
            ->orderByDesc('c')
            ->limit(10)
            ->get();

        return $rows->map(fn ($r) => [
            'name' => (string) ($r->name ?? ''),
            'variant' => (string) ($r->variant ?? ''),
            'count' => (int) $r->c,
            'value' => (int) $r->v,
        ])->all();
    }

    /**
     * Void event log — a flat, paginated list of individual cancellations
     * (which order, when, why, how much) backing the "Nhật ký huỷ" table.
     * A UNION of the same two non-overlapping lenses used by voids():
     *   - one row per wholly-voided order (value = its item subtotals,
     *     item_count = how many lines it held),
     *   - one row per per-item void on a NON-voided order.
     * Newest first. Mirrors the workstation LAN shape.
     *
     * @return array{from: string, to: string, type: string, total: int, page: int, per_page: int, rows: list<array<string, mixed>>, generated_at: string}
     */
    public function voidEvents(
        string $branchId,
        string $organizationId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $type,
        int $page,
        int $perPage,
    ): array {
        $type = in_array($type, ['order', 'item'], true) ? $type : 'all';
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $f = $from->startOfDay();
        $t = $to->endOfDay();

        $inner = $this->voidEventsInner($branchId, $organizationId, $f, $t, $type);

        $total = (int) DB::query()->fromSub($inner, 'v')->count();
        $rows = DB::query()->fromSub($inner, 'v')
            ->orderByDesc('voided_at')
            ->orderByDesc('order_code')
            ->forPage($page, $perPage)
            ->get();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'type' => $type,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'rows' => $rows->map(fn ($r) => [
                'kind' => (string) $r->kind,
                'order_id' => (string) $r->order_id,
                'order_code' => (string) ($r->order_code ?? ''),
                'voided_at' => $this->toIso($r->voided_at),
                'reason' => (string) ($r->reason ?? ''),
                'item_name' => trim((string) ($r->item_name ?? '')),
                'variant' => trim((string) ($r->variant ?? '')),
                'quantity' => (int) $r->quantity,
                'item_count' => (int) $r->item_count,
                'value' => (int) $r->value,
            ])->all(),
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Builds the (un-paginated) void-event query for the given lens. Column
     * order is identical in both UNION halves so the compound select lines up.
     */
    private function voidEventsInner(string $branchId, string $organizationId, \DateTimeInterface $from, \DateTimeInterface $to, string $type): Builder
    {
        $orderQ = DB::table('customer_orders as co')
            ->leftJoin('customer_order_items as coi', 'coi.customer_order_id', '=', 'co.id')
            ->where('co.branch_id', $branchId)->where('co.organization_id', $organizationId)
            ->where('co.status', 'voided')->whereNull('co.deleted_at')
            ->whereRaw('COALESCE(co.voided_at, co.created_at) BETWEEN ? AND ?', [$from, $to])
            ->groupBy('co.id', 'co.order_code', 'co.voided_at', 'co.created_at', 'co.void_reason')
            ->selectRaw("'order' as kind, co.id as order_id, co.order_code as order_code, ".
                'COALESCE(co.voided_at, co.created_at) as voided_at, '.
                "COALESCE(NULLIF(TRIM(co.void_reason), ''), '') as reason, ".
                "'' as item_name, '' as variant, 0 as quantity, ".
                'COUNT(coi.id) as item_count, COALESCE(SUM(coi.subtotal), 0) as value');

        $itemQ = DB::table('customer_order_items as coi')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->leftJoin('product_skus as ps', 'ps.id', '=', 'coi.product_sku_id')
            ->leftJoin('products as p', 'p.id', '=', 'ps.product_id')
            ->where('co.branch_id', $branchId)->where('co.organization_id', $organizationId)
            ->where('coi.status', 'voided')->where('co.status', '!=', 'voided')->whereNull('co.deleted_at')
            ->whereRaw('COALESCE(coi.voided_at, coi.created_at) BETWEEN ? AND ?', [$from, $to]);

        // Follow the operator's pos-web language for the item + variant name.
        $this->joinTranslations($itemQ, 'product_translations', 'product_id', 'p.id', 'p_t');
        $this->joinTranslations($itemQ, 'product_sku_translations', 'product_sku_id', 'ps.id', 'ps_t');
        $itemName = $this->translatedNameExpr('p_t', 'p.name');
        $itemVariant = $this->translatedNameExpr('ps_t', 'ps.name');

        $itemQ->selectRaw("'item' as kind, co.id as order_id, co.order_code as order_code, ".
            'COALESCE(coi.voided_at, coi.created_at) as voided_at, '.
            "COALESCE(NULLIF(TRIM(coi.void_reason), ''), '') as reason, ".
            "COALESCE({$itemName}, coi.product_sku_id) as item_name, COALESCE({$itemVariant}, '') as variant, ".
            'coi.quantity as quantity, 1 as item_count, coi.subtotal as value');

        return match ($type) {
            'order' => $orderQ,
            'item' => $itemQ,
            default => $orderQ->unionAll($itemQ),
        };
    }

    /**
     * Normalise a DB datetime (MySQL 'Y-m-d H:i:s' or SQLite ISO string) to
     * an ISO-8601 string for the client.
     */
    private function toIso(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }
        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * Driver-agnostic DATE_FORMAT shim. Mirrors ShopDashboardService::dateFmt.
     */
    private function dateFmt(string $column, string $mysqlFmt): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "strftime('{$mysqlFmt}', {$column})";
        }

        return "DATE_FORMAT({$column}, '{$mysqlFmt}')";
    }

    /**
     * Weekday expressed as 0..6 with Monday=0 to align with JS i18n (weekday 0 = 月).
     */
    private function weekdayExpr(string $column): string
    {
        if (DB::getDriverName() === 'sqlite') {
            // strftime '%w' → 0..6 with Sunday=0. Rebase to Monday=0.
            return "((CAST(strftime('%w', {$column}) AS INTEGER) + 6) % 7)";
        }

        // MySQL WEEKDAY() is already Monday=0..Sunday=6.
        return "WEEKDAY({$column})";
    }

    // ──────────────────────────────────────────────────────────────────
    //  商品別売上 — Sales by product / SKU
    // ──────────────────────────────────────────────────────────────────

    /**
     * Rank products (or SKU variants) by closed-order revenue across the
     * window. Powers the "Theo sản phẩm" tab on the pos-web revenue
     * screen. Mirrors the Japanese mockup's 商品別売上 layout:
     *
     *   - 商品名 (translated, locale-aware via product_translations)
     *   - 販売総売上 (sum of order_item.subtotal — already net of
     *     promotion + topping)
     *   - 構成比 (share % of period revenue total)
     *
     * Optional `level=sku` switches the row to per-SKU rows
     * (バリエーション単位で表示). Optional `category_id` filters via the
     * product_category pivot.
     *
     * @return array{
     *   from: string,
     *   to: string,
     *   level: string,
     *   sort: string,
     *   category_id: ?string,
     *   total_revenue: int,
     *   total_quantity: int,
     *   rows: list<array{
     *     id: string,
     *     name: string,
     *     sku: ?string,
     *     category_id: ?string,
     *     category_name: ?string,
     *     quantity: int,
     *     revenue: int,
     *     share_pct: float,
     *   }>,
     *   generated_at: string,
     * }
     */
    public function byProduct(
        string $branchId,
        string $organizationId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $level = self::PRODUCT_LEVEL_PRODUCT,
        ?string $categoryId = null,
        string $sort = self::PRODUCT_SORT_REVENUE,
        int $page = 1,
        int $perPage = 25,
        ?string $brandId = null,
    ): array {
        $level = $level === self::PRODUCT_LEVEL_SKU
            ? self::PRODUCT_LEVEL_SKU
            : self::PRODUCT_LEVEL_PRODUCT;

        $sort = match ($sort) {
            self::PRODUCT_SORT_QUANTITY => self::PRODUCT_SORT_QUANTITY,
            self::PRODUCT_SORT_SHARE => self::PRODUCT_SORT_SHARE,
            default => self::PRODUCT_SORT_REVENUE,
        };

        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));

        $fromAt = $from->startOfDay();
        $toAt = $to->endOfDay();

        $query = DB::table('customer_order_items as coi')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->join('product_skus as ps', 'ps.id', '=', 'coi.product_sku_id')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->where('co.branch_id', $branchId)
            ->where('co.organization_id', $organizationId)
            ->where('co.status', 'closed')
            ->whereNull('co.deleted_at')
            ->whereBetween('co.created_at', [$fromAt, $toAt]);

        // Resolve the set of menus this shop exposes — per-branch menus
        // first, falling back to brand-wide menus when the branch isn't
        // specifically targeted. Sections come from those menus only,
        // so the "Danh mục" dropdown matches what the shop actually
        // serves rather than the brand catalog.
        $shopMenuIds = $this->sections()->menuIdsForShop($branchId, $brandId);

        if ($categoryId !== null) {
            // category_id from the wire is treated as a menu_section_id.
            // Expand to every section in this shop's menus that shares
            // the same display name so picking "Main" from the
            // brand-wide master also catches products attached to the
            // per-branch override's "Main" (different id, same label).
            $sectionIds = $this->sections()->sectionIdsSharingName($categoryId, $shopMenuIds);
            $query->join('menu_products as mp_filter', function ($join) use ($sectionIds, $shopMenuIds) {
                $join->on('mp_filter.product_id', '=', 'p.id')
                    ->whereIn('mp_filter.menu_section_id', $sectionIds);
                if (! empty($shopMenuIds)) {
                    $join->whereIn('mp_filter.menu_id', $shopMenuIds);
                }
            });
        }

        // Surface a section label per row by joining the menu_products
        // pivot scoped to this shop's menus + the menu_sections row.
        // A product can appear in multiple sections across menus — we
        // pick the lowest section id deterministically so the label
        // stays stable between requests.
        $query->leftJoin('menu_products as mp', function ($join) use ($shopMenuIds) {
            $join->on('mp.product_id', '=', 'p.id');
            if (! empty($shopMenuIds)) {
                $join->whereIn('mp.menu_id', $shopMenuIds);
            }
        })
            ->leftJoin('menu_sections as ms', 'ms.id', '=', 'mp.menu_section_id');

        $this->joinTranslations($query, 'product_translations', 'product_id', 'p.id', 'p_t');
        $this->joinTranslations($query, 'product_sku_translations', 'product_sku_id', 'ps.id', 'ps_t');

        $productName = $this->translatedNameExpr('p_t', 'p.name');
        $skuName = $this->translatedNameExpr('ps_t', 'ps.name');
        $categoryName = 'ms.name';

        if ($level === self::PRODUCT_LEVEL_SKU) {
            $query->select([
                DB::raw('ps.id as id'),
                DB::raw("MAX(CONCAT_WS(' / ', {$productName}, {$skuName})) as name"),
                DB::raw('MAX(ps.sku) as sku'),
                DB::raw('MIN(ms.id) as category_id'),
                DB::raw("MIN({$categoryName}) as category_name"),
                DB::raw('SUM(coi.quantity) as quantity'),
                DB::raw('SUM(coi.subtotal) as revenue'),
            ])->groupBy('ps.id');
        } else {
            $query->select([
                DB::raw('p.id as id'),
                DB::raw("MAX({$productName}) as name"),
                DB::raw('NULL as sku'),
                DB::raw('MIN(ms.id) as category_id'),
                DB::raw("MIN({$categoryName}) as category_name"),
                DB::raw('SUM(coi.quantity) as quantity'),
                DB::raw('SUM(coi.subtotal) as revenue'),
            ])->groupBy('p.id');
        }

        $orderBy = match ($sort) {
            self::PRODUCT_SORT_QUANTITY => 'quantity',
            self::PRODUCT_SORT_SHARE => 'revenue', // share% derives from revenue
            default => 'revenue',
        };
        $query->orderByDesc($orderBy);

        // ── Totals + total count: a separate "wrapper" SELECT around
        // the grouped query gives us both `total` (row count across
        // ALL pages) and `total_revenue`/`total_quantity` (sums across
        // ALL filtered products, not just the current page) in a single
        // round-trip. Computing share_pct on the per-page slice would
        // make the numbers add up to whatever the page reaches — wrong
        // semantics. Using the full-filter totals makes share% stable
        // across pagination.
        $aggregateRow = DB::query()
            ->fromSub($query, 'agg')
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(revenue), 0) AS total_revenue, COALESCE(SUM(quantity), 0) AS total_quantity')
            ->first();

        $total = (int) ($aggregateRow->total ?? 0);
        $totalRevenue = (int) ($aggregateRow->total_revenue ?? 0);
        $totalQuantity = (int) ($aggregateRow->total_quantity ?? 0);

        $lastPage = (int) max(1, (int) ceil($total / $perPage));
        $currentPage = min($page, $lastPage);

        // Re-issue the grouped query for the page slice. We can't reuse
        // `$query` for both because forSub() above wraps + consumes it.
        $raw = $query->offset(($currentPage - 1) * $perPage)
            ->limit($perPage)
            ->get();

        // When the request is filtered by a specific section, every
        // returned row IS in that section (the inner join guarantees
        // it). A product can sit in multiple sections though, and the
        // unfiltered `MIN(ms.id)` label might pick a DIFFERENT section
        // — confusing because the operator just narrowed to メイン and
        // sees おすすめ in the rows. Override the label so the table
        // matches the filter the user actually applied.
        $filterCategoryName = null;
        if ($categoryId !== null) {
            $filterCategoryName = $this->sections()->sectionName($categoryId);
        }

        $rows = $raw->map(function ($r) use ($totalRevenue, $categoryId, $filterCategoryName) {
            $rev = (int) $r->revenue;

            return [
                'id' => (string) $r->id,
                'name' => (string) ($r->name ?? ''),
                'sku' => $r->sku !== null ? (string) $r->sku : null,
                'category_id' => $categoryId !== null
                    ? $categoryId
                    : ($r->category_id !== null ? (string) $r->category_id : null),
                'category_name' => $filterCategoryName !== null
                    ? (string) $filterCategoryName
                    : ($r->category_name !== null ? (string) $r->category_name : null),
                'quantity' => (int) $r->quantity,
                'revenue' => $rev,
                'share_pct' => $totalRevenue > 0
                    ? round($rev * 100 / $totalRevenue, 1)
                    : 0.0,
            ];
        })->all();

        // plan-043 T4.6 (§3.13) — the by-product tab SUMs coi.subtotal (net /
        // 税抜), while the summary tab SUMs total_amount (gross / 税込). Surface
        // both conventions + the per-rate tax so the two tabs reconcile:
        // `total_revenue` stays net (unchanged for BC), `net`/`gross`/`tax`
        // make the relationship explicit.
        $breakdown = $this->taxBreakdownForWindow($branchId, $organizationId, $fromAt, $toAt);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'level' => $level,
            'sort' => $sort,
            'category_id' => $categoryId,
            'total_revenue' => $totalRevenue,
            'total_quantity' => $totalQuantity,
            'net' => (int) round($breakdown['net']),
            'gross' => (int) round($breakdown['gross']),
            'tax' => (int) round($breakdown['tax']),
            'by_tax_rate' => $this->intBreakdownRows($breakdown['by_rate']),
            'rows' => $rows,
            'meta' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'available_categories' => $this->sections()->sectionsForShop($branchId, $brandId),
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Categories belonging to the shop's brand. Surfaced alongside the
     * by-product rows so the filter dropdown has the full list, not
     * just categories with sales in the current window.
     *
     * @return list<array{id: string, name: string}>
     */
    private function translatedNameExpr(string $alias, string $raw): string
    {
        $locale = app()->getLocale();
        $fallback = array_values(array_unique([$locale, 'ja', 'en', 'vi']));
        $parts = array_map(fn ($l) => "{$alias}_{$l}.name", $fallback);
        $parts[] = $raw;

        return 'COALESCE('.implode(', ', $parts).')';
    }

    private function joinTranslations(
        Builder $query,
        string $table,
        string $fkColumn,
        string $joinOn,
        string $alias,
    ): void {
        foreach (['ja', 'en', 'vi'] as $locale) {
            $as = "{$alias}_{$locale}";
            $query->leftJoin("{$table} as {$as}", fn ($j) => $j
                ->on("{$as}.{$fkColumn}", '=', $joinOn)
                ->where("{$as}.locale", $locale));
        }
    }
}
