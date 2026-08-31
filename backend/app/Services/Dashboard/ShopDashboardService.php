<?php

namespace App\Services\Dashboard;

use App\Models\CustomerOrder;
use App\Models\Table;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderTaxBreakdownAggregator;
use App\Support\BusinessClock;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShopDashboardService
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function dateFmt(string $column, string $mysqlFmt): string
    {
        if (DB::getDriverName() === 'sqlite') {
            $sqliteFmt = strtr($mysqlFmt, ['%Y' => '%Y', '%m' => '%m', '%d' => '%d', '%u' => '%W']);

            return "strftime('{$sqliteFmt}', {$column})";
        }

        return "DATE_FORMAT({$column}, '{$mysqlFmt}')";
    }

    private function deltaPercent(float $current, float $previous): int
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    /**
     * "Today" for a SHOP is the shop's day (#1091), not the server's. A Tokyo
     * dashboard opened at 08:00 local must not still be showing yesterday's
     * revenue because UTC has not ticked over yet — and a Hanoi manager viewing
     * the Tokyo shop must see Tokyo's day, not their own.
     *
     * @return array{from: Carbon, to: Carbon, prev_from: Carbon, prev_to: Carbon}
     */
    private function todayRange(string $branchId): array
    {
        $today = BusinessClock::businessDate($branchId);
        $yesterday = BusinessClock::now($branchId)->subDay()->toDateString();

        // Half-open [from, to): an order closed at exactly the shop's midnight
        // belongs to ONE day, not both. The callers below compare with
        // >= from AND < to for the same reason.
        [$from, $to] = BusinessClock::utcRangeForBusinessDates($branchId, $today, $today);
        [$prevFrom, $prevTo] = BusinessClock::utcRangeForBusinessDates($branchId, $yesterday, $yesterday);

        return [
            'from' => Carbon::instance($from),
            'to' => Carbon::instance($to),
            'prev_from' => Carbon::instance($prevFrom),
            'prev_to' => Carbon::instance($prevTo),
        ];
    }

    private function mapOrderStatus(CustomerOrderStatusEnum|string $status): string
    {
        $value = $status instanceof CustomerOrderStatusEnum ? $status->value : $status;

        return match ($value) {
            'closed' => 'completed',
            'voided' => 'cancelled',
            default => 'in_progress',
        };
    }

    private function mapItemStatus(string $status): string
    {
        return match ($status) {
            'preparing' => 'in_progress',
            default => 'pending',
        };
    }

    /**
     * Build a COALESCE SQL expression that resolves a translated name column.
     *
     * @param  string  $alias  Alias prefix (e.g. "p_t" → "p_t_ja", "p_t_en", "p_t_vi")
     * @param  string  $raw  Fully-qualified fallback column (e.g. "p.name")
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

    // ── KPI Cards ─────────────────────────────────────────────────────────────

    public function kpis(string $branchId, string $organizationId): array
    {
        $r = $this->todayRange($branchId);

        $closedQuery = fn (Carbon $from, Carbon $to) => CustomerOrder::query()
            ->where('branch_id', $branchId)
            ->where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);

        $allQuery = fn (Carbon $from, Carbon $to) => CustomerOrder::query()
            ->where('branch_id', $branchId)
            ->where('organization_id', $organizationId)
            ->where('status', '!=', 'voided')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);

        $currentRevenue = (float) $closedQuery($r['from'], $r['to'])->sum('total_amount');
        $prevRevenue = (float) $closedQuery($r['prev_from'], $r['prev_to'])->sum('total_amount');

        $currentOrders = $allQuery($r['from'], $r['to'])->count();
        $prevOrders = $allQuery($r['prev_from'], $r['prev_to'])->count();

        // Table occupancy (occupied + reserved count vs total active tables)
        $tables = Table::where('branch_id', $branchId)->where('is_active', true);
        $totalTables = (clone $tables)->count();
        $occupiedTables = (clone $tables)->whereIn('status', ['occupied', 'reserved'])->count();

        // Low-stock items: stock_levels where quantity < min_stock via branch warehouses
        $lowStockCount = DB::table('stock_levels as sl')
            ->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')
            ->where('w.branch_id', $branchId)
            ->whereNotNull('sl.min_stock')
            ->whereColumn('sl.quantity', '<', 'sl.min_stock')
            ->count();

        // plan-043 T4.6 — 税抜 / 税込 + per-rate tax for today's closed orders,
        // consistent with PosRevenueService for the same period.
        $tax = $this->taxBreakdown(
            fn () => $closedQuery($r['from'], $r['to'])->pluck('id'),
        );

        return [
            'revenue' => [
                'value' => (int) round((float) $currentRevenue),
                'delta_pct' => $this->deltaPercent($currentRevenue, $prevRevenue),
                'net' => (int) round($tax['net']),
                'gross' => (int) round((float) $currentRevenue),
                'tax' => (int) round($tax['tax']),
                'by_tax_rate' => $tax['by_rate_int'],
            ],
            'orders' => [
                'value' => $currentOrders,
                'delta_pct' => $this->deltaPercent($currentOrders, $prevOrders),
            ],
            'table_occupancy' => [
                'occupied' => $occupiedTables,
                'total' => $totalTables,
            ],
            'low_stock' => [
                'value' => $lowStockCount,
            ],
        ];
    }

    /**
     * Shared per-rate consumption-tax rollup for a set of closed order ids
     * (plan-043 T4.6). Reuses the frozen per-line snapshots via the shared
     * aggregator — never recomputes tax math.
     *
     * @param  callable(): iterable<int|string>  $orderIds
     * @return array{net: float, tax: float, gross: float, by_rate_int: list<array{rate: float, taxable: int, tax: int}>}
     */
    private function taxBreakdown(callable $orderIds): array
    {
        $breakdown = (new OrderTaxBreakdownAggregator)->forOrders($orderIds());

        return [
            'net' => $breakdown['net'],
            'tax' => $breakdown['tax'],
            'gross' => $breakdown['gross'],
            'by_rate_int' => array_map(fn (array $row) => [
                'rate' => $row['rate'],
                'taxable' => (int) round($row['taxable']),
                'tax' => (int) round($row['tax']),
            ], $breakdown['by_rate']),
        ];
    }

    // ── Revenue Trend (last 7 days) ───────────────────────────────────────────

    public function revenueTrend(string $branchId, string $organizationId): Collection
    {
        // Seven SHOP days, so the trend's last bucket is the shop's today.
        $today = BusinessClock::businessDate($branchId);
        $sixDaysAgo = BusinessClock::now($branchId)->subDays(6)->toDateString();
        [$from, $to] = BusinessClock::utcRangeForBusinessDates($branchId, $sixDaysAgo, $today);
        $dateFrom = Carbon::instance($from);
        $dateTo = Carbon::instance($to);

        return CustomerOrder::query()
            ->select([
                DB::raw(self::dateFmt('created_at', '%Y-%m-%d').' as day'),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(*) as orders'),
            ])
            ->where('branch_id', $branchId)
            ->where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->where('created_at', '>=', $dateFrom)
            ->where('created_at', '<', $dateTo)
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => $row->day,
                'revenue' => (int) round((float) $row->revenue),
                'orders' => (int) $row->orders,
            ]);
    }

    // ── Table Status ──────────────────────────────────────────────────────────

    public function tableStatus(string $branchId): Collection
    {
        $counts = Table::where('branch_id', $branchId)
            ->where('is_active', true)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $statuses = ['free', 'occupied', 'reserved', 'cleaning', 'out_of_service'];

        return collect($statuses)->map(fn ($status) => [
            'status' => $status,
            'count' => (int) ($counts[$status]->count ?? 0),
        ]);
    }

    // ── Top Items (today) ─────────────────────────────────────────────────────

    public function topItems(string $branchId, string $organizationId, int $limit = 5): Collection
    {
        $today = now();
        $yesterday = now()->subDay();

        $baseQuery = fn (Carbon $from, Carbon $to) => DB::table('customer_order_items as coi')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->join('product_skus as ps', 'ps.id', '=', 'coi.product_sku_id')
            ->where('co.branch_id', $branchId)
            ->where('co.organization_id', $organizationId)
            ->where('co.status', 'closed')
            ->whereBetween('co.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNull('co.deleted_at');

        $currentQuery = $baseQuery($today->copy(), $today->copy())
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->leftJoin('product_category as pc', 'pc.product_id', '=', 'p.id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'pc.category_id');

        $this->joinTranslations($currentQuery, 'product_translations', 'product_id', 'p.id', 'p_t');
        $this->joinTranslations($currentQuery, 'category_translations', 'category_id', 'cat.id', 'cat_t');

        $productName = $this->translatedNameExpr('p_t', 'p.name');
        $categoryName = $this->translatedNameExpr('cat_t', 'cat.name');

        $current = $currentQuery
            ->select([
                'p.id as product_id',
                DB::raw("MAX({$productName}) as product_name"),
                DB::raw("MIN({$categoryName}) as category_name"),
                DB::raw('SUM(coi.quantity) as sold'),
                DB::raw('SUM(coi.subtotal) as revenue'),
            ])
            ->groupBy('p.id')
            ->orderByDesc('sold')
            ->limit($limit)
            ->get()
            ->keyBy('product_id');

        if ($current->isEmpty()) {
            return collect();
        }

        $productIds = $current->keys()->all();
        $previous = $baseQuery($yesterday->copy(), $yesterday->copy())
            ->select('ps.product_id', DB::raw('SUM(coi.quantity) as sold'))
            ->whereIn('ps.product_id', $productIds)
            ->groupBy('ps.product_id')
            ->get()
            ->keyBy('product_id');

        return $current->map(fn ($row) => [
            'product_id' => $row->product_id,
            'product_name' => $row->product_name,
            'category_name' => $row->category_name ?? '—',
            'sold' => (int) $row->sold,
            'revenue' => (int) round((float) $row->revenue),
            'trend' => (int) $row->sold >= (int) ($previous[$row->product_id]->sold ?? 0) ? 'up' : 'down',
        ])->values();
    }

    // ── Production Queue ──────────────────────────────────────────────────────

    public function productionQueue(string $branchId, string $organizationId, int $limit = 10): Collection
    {
        $query = DB::table('customer_order_items as coi')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->join('product_skus as ps', 'ps.id', '=', 'coi.product_sku_id')
            ->join('products as p', 'p.id', '=', 'ps.product_id');

        $this->joinTranslations($query, 'product_translations', 'product_id', 'p.id', 'p_t');

        $productName = $this->translatedNameExpr('p_t', 'p.name');

        return $query
            ->select([
                'co.order_code',
                DB::raw("MAX({$productName}) as product_name"),
                DB::raw('SUM(coi.quantity) as quantity'),
                'coi.status',
            ])
            ->where('co.branch_id', $branchId)
            ->where('co.organization_id', $organizationId)
            ->whereNotIn('co.status', ['closed', 'voided'])
            ->whereIn('coi.status', ['pending', 'preparing', 'ready'])
            ->tap(function ($query) use ($branchId): void {
                $today = BusinessClock::businessDate($branchId);
                [$from, $to] = BusinessClock::utcRangeForBusinessDates($branchId, $today, $today);
                $query->where('co.created_at', '>=', $from)->where('co.created_at', '<', $to);
            })
            ->whereNull('co.deleted_at')
            ->groupBy('co.order_code', 'coi.status')
            ->orderBy('co.created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'order_code' => $row->order_code,
                'product_name' => $row->product_name,
                'quantity' => (int) $row->quantity,
                'status' => $this->mapItemStatus($row->status),
            ]);
    }

    // ── Recent Orders ─────────────────────────────────────────────────────────

    public function recentOrders(string $branchId, string $organizationId, int $limit = 5): Collection
    {
        return CustomerOrder::query()
            ->with(['table:id,code'])
            ->withCount('items')
            ->where('branch_id', $branchId)
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'order_code' => $order->order_code,
                'table_code' => $order->table?->code,
                'items_count' => $order->items_count,
                'total_amount' => (int) round((float) $order->total_amount),
                'status' => $this->mapOrderStatus($order->status),
                'created_at' => $order->created_at->toIso8601String(),
            ]);
    }
}
