<?php

namespace App\Services\Dashboard;

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\Product;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderTaxBreakdownAggregator;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * DB-agnostic date formatting expression (MySQL vs SQLite in tests).
     * MySQL format strings are passed as-is; SQLite uses strftime equivalents.
     */
    private static function dateFmt(string $column, string $mysqlFmt): string
    {
        if (DB::getDriverName() === 'sqlite') {
            $sqliteFmt = strtr($mysqlFmt, ['%Y' => '%Y', '%m' => '%m', '%d' => '%d', '%u' => '%W']);

            return "strftime('{$sqliteFmt}', {$column})";
        }

        return "DATE_FORMAT({$column}, '{$mysqlFmt}')";
    }

    /** @return array{from: Carbon, to: Carbon, prev_from: Carbon, prev_to: Carbon} */
    private function periodRange(string $period): array
    {
        return match ($period) {
            'week' => [
                'from' => now()->startOfWeek(),
                'to' => now()->endOfWeek(),
                'prev_from' => now()->subWeek()->startOfWeek(),
                'prev_to' => now()->subWeek()->endOfWeek(),
            ],
            'year' => [
                'from' => now()->startOfYear(),
                'to' => now()->endOfYear(),
                'prev_from' => now()->subYear()->startOfYear(),
                'prev_to' => now()->subYear()->endOfYear(),
            ],
            default => [
                'from' => now()->startOfMonth(),
                'to' => now()->endOfMonth(),
                // `startOfMonth()` TRƯỚC, `subMonth()` SAU. Thứ tự ngược lại thì
                // Carbon TRÀN TỚI TRƯỚC khi ngày không tồn tại ở tháng đích:
                // 2026-07-31 → subMonth() → 2026-07-01 (vì 31/6 không có), nên
                // `prev_from`/`prev_to` thành 01/07–31/07 — tức KỲ TRƯỚC BẰNG
                // ĐÚNG KỲ HIỆN TẠI. Dashboard so tháng này với chính nó: delta_pct
                // ra 0, trend lật. Xảy ra mỗi tháng một ngày, có lịch chứ không
                // ngẫu nhiên (#1412).
                'prev_from' => now()->startOfMonth()->subMonth(),
                'prev_to' => now()->startOfMonth()->subMonth()->endOfMonth(),
            ],
        };
    }

    private function deltaPercent(float $current, float $previous): int
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    /**
     * Build a COALESCE SQL expression that resolves a translated name column
     * with fallback chain: current locale → ja → en → vi → raw column.
     *
     * Usage: call joinTranslations() on the query first, then pass the returned
     * expression to DB::raw() in the select list.
     *
     * @param  string  $alias  Alias prefix used when joining (e.g. "cat_t" → "cat_t_ja", "cat_t_en", "cat_t_vi")
     * @param  string  $raw  Fully-qualified fallback column (e.g. "cat.name")
     * @return string SQL fragment suitable for DB::raw()
     */
    private function translatedNameExpr(string $alias, string $raw): string
    {
        $locale = app()->getLocale();
        $fallback = array_values(array_unique([$locale, 'ja', 'en', 'vi']));
        $parts = array_map(fn ($l) => "{$alias}_{$l}.name", $fallback);
        $parts[] = $raw;

        return 'COALESCE('.implode(', ', $parts).')';
    }

    /**
     * LEFT JOIN a translations table three times (ja / en / vi) onto a query.
     *
     * @param  string  $table  Translation table name (e.g. "category_translations")
     * @param  string  $fkColumn  FK column on the translation table (e.g. "category_id")
     * @param  string  $joinOn  Source column to join on (e.g. "cat.id")
     * @param  string  $alias  Alias prefix (e.g. "cat_t" → "cat_t_ja", …)
     */
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

    private function mapOrderStatus(CustomerOrderStatusEnum|string $status): string
    {
        $value = $status instanceof CustomerOrderStatusEnum ? $status->value : $status;

        return match ($value) {
            'closed' => 'completed',
            'voided' => 'cancelled',
            default => 'in_progress',
        };
    }

    // ── KPI Cards ─────────────────────────────────────────────────────────────

    public function kpis(string $brandId, string $organizationId, string $period): array
    {
        $r = $this->periodRange($period);

        $closedOrderQuery = fn (Carbon $from, Carbon $to) => CustomerOrder::query()
            ->where('brand_id', $brandId)
            ->where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->whereBetween('created_at', [$from, $to]);

        $allOrderQuery = fn (Carbon $from, Carbon $to) => CustomerOrder::query()
            ->where('brand_id', $brandId)
            ->where('organization_id', $organizationId)
            ->where('status', '!=', 'voided')
            ->whereBetween('created_at', [$from, $to]);

        $currentRevenue = (float) $closedOrderQuery($r['from'], $r['to'])->sum('total_amount');
        $prevRevenue = (float) $closedOrderQuery($r['prev_from'], $r['prev_to'])->sum('total_amount');

        $currentOrders = $allOrderQuery($r['from'], $r['to'])->count();
        $prevOrders = $allOrderQuery($r['prev_from'], $r['prev_to'])->count();

        // KPI counts both `approved` and `active` products — once a product
        // graduates from `approved` to `active` it's still a live SKU on the
        // menu, and the dashboard KPI represents the catalog headcount.
        $publishedStatuses = ['approved', 'active'];

        $currentProducts = Product::where('brand_id', $brandId)
            ->where('organization_id', $organizationId)
            ->whereIn('status', $publishedStatuses)
            ->count();

        $prevProducts = Product::where('brand_id', $brandId)
            ->where('organization_id', $organizationId)
            ->whereIn('status', $publishedStatuses)
            ->where('created_at', '<=', $r['prev_to'])
            ->count();

        // Shops = distinct branches that have had orders for this brand
        $shopsCount = CustomerOrder::where('brand_id', $brandId)
            ->where('organization_id', $organizationId)
            ->distinct()
            ->count('branch_id');

        // plan-043 T4.6 — 税抜 / 税込 + per-rate tax for the period's closed
        // orders, consistent with PosRevenueService for the same window.
        $breakdown = (new OrderTaxBreakdownAggregator)->forOrders(
            $closedOrderQuery($r['from'], $r['to'])->pluck('id'),
        );

        return [
            'revenue' => [
                'value' => (int) round((float) $currentRevenue),
                'delta_pct' => $this->deltaPercent($currentRevenue, $prevRevenue),
                'net' => (int) round($breakdown['net']),
                'gross' => (int) round((float) $currentRevenue),
                'tax' => (int) round($breakdown['tax']),
                'by_tax_rate' => array_map(fn (array $row) => [
                    'rate' => $row['rate'],
                    'taxable' => (int) round($row['taxable']),
                    'tax' => (int) round($row['tax']),
                ], $breakdown['by_rate']),
            ],
            'orders' => [
                'value' => $currentOrders,
                'delta_pct' => $this->deltaPercent($currentOrders, $prevOrders),
            ],
            'products' => [
                'value' => $currentProducts,
                'delta_pct' => $this->deltaPercent($currentProducts, $prevProducts),
            ],
            'shops' => [
                'value' => $shopsCount,
                'delta_pct' => 0,
            ],
        ];
    }

    // ── Revenue Chart ─────────────────────────────────────────────────────────

    public function revenueChart(
        string $brandId,
        string $organizationId,
        Carbon $dateFrom,
        Carbon $dateTo,
        string $groupBy,
    ): Collection {
        $sqlFormat = match ($groupBy) {
            'week' => '%Y-W%u',
            'year' => '%Y',
            default => '%Y-%m',
        };

        return CustomerOrder::query()
            ->select([
                DB::raw(self::dateFmt('created_at', $sqlFormat).' as period'),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(*) as orders'),
            ])
            ->where('brand_id', $brandId)
            ->where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->whereBetween('created_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'revenue' => (int) round((float) $row->revenue),
                'orders' => (int) $row->orders,
            ]);
    }

    // ── Category Sales Pie ────────────────────────────────────────────────────

    public function categorySales(string $brandId, string $organizationId, string $period): Collection
    {
        $r = $this->periodRange($period);

        $query = DB::table('customer_order_items as coi')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->join('product_skus as ps', 'ps.id', '=', 'coi.product_sku_id')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->join('product_category as pc', 'pc.product_id', '=', 'p.id')
            ->join('categories as cat', 'cat.id', '=', 'pc.category_id');

        $this->joinTranslations($query, 'category_translations', 'category_id', 'cat.id', 'cat_t');

        $catName = $this->translatedNameExpr('cat_t', 'cat.name');

        $rows = $query
            ->select([
                'cat.id as category_id',
                DB::raw("MAX({$catName}) as category_name"),
                DB::raw('SUM(coi.subtotal) as revenue'),
            ])
            ->where('co.brand_id', $brandId)
            ->where('co.organization_id', $organizationId)
            ->where('co.status', 'closed')
            ->whereBetween('co.created_at', [$r['from'], $r['to']])
            ->whereNull('co.deleted_at')
            ->groupBy('cat.id')
            ->orderByDesc('revenue')
            ->get();

        $total = $rows->sum('revenue');

        if ($total == 0) {
            return collect();
        }

        return $rows->map(fn ($row) => [
            'category_id' => $row->category_id,
            'category_name' => $row->category_name,
            'revenue' => (int) round((float) $row->revenue),
            'percentage' => round(($row->revenue / $total) * 100, 1),
        ]);
    }

    // ── Shop Performance ──────────────────────────────────────────────────────

    public function shopPerformance(string $brandId, string $organizationId, string $period): Collection
    {
        $r = $this->periodRange($period);

        // Revenue per branch in current period
        $currentRevenues = CustomerOrder::query()
            ->select('branch_id', DB::raw('SUM(total_amount) as revenue'))
            ->where('brand_id', $brandId)
            ->where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->whereBetween('created_at', [$r['from'], $r['to']])
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        if ($currentRevenues->isEmpty()) {
            return collect();
        }

        // Target = avg monthly revenue over the last 3 complete months × 1.1
        // #1424 — mốc đầu tháng TRƯỚC, trừ tháng SAU (Carbon tràn tháng).
        $threeMonthsAgo = now()->startOfMonth()->subMonths(3);
        // Cùng bẫy tràn tháng như `periodRange()` — xem #1412.
        $lastMonthEnd = now()->startOfMonth()->subMonth()->endOfMonth();

        $targets = CustomerOrder::query()
            ->select('branch_id', DB::raw('SUM(total_amount) as monthly_revenue'), DB::raw(self::dateFmt('created_at', '%Y-%m').' as month'))
            ->where('brand_id', $brandId)
            ->where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->whereBetween('created_at', [$threeMonthsAgo, $lastMonthEnd])
            ->groupBy('branch_id', 'month')
            ->get()
            ->groupBy('branch_id')
            ->map(fn ($rows) => (int) round($rows->avg('monthly_revenue') * 1.1));

        // Load branch names + slugs. Use withTrashed() so soft-deleted branches
        // still resolve a name — their historical orders remain in the revenue
        // aggregation, so dropping the name would leave orphaned "—" rows.
        $branchIds = $currentRevenues->keys()->all();
        $branches = Branch::withTrashed()->whereIn('id', $branchIds)->get(['id', 'name', 'slug'])->keyBy('id');

        return $currentRevenues->map(function ($row) use ($targets, $branches) {
            $revenue = (int) round((float) $row->revenue);
            $target = $targets[$row->branch_id] ?? $revenue;
            $branch = $branches[$row->branch_id] ?? null;

            return [
                'branch_id' => $row->branch_id,
                'branch_slug' => $branch?->slug,
                'branch_name' => $branch?->name ?? '—',
                'revenue' => $revenue,
                'target' => $target > 0 ? $target : $revenue,
            ];
        })->values();
    }

    // ── Top Products ──────────────────────────────────────────────────────────

    public function topProducts(string $brandId, string $organizationId, string $period, int $limit = 5): Collection
    {
        $r = $this->periodRange($period);

        $baseQuery = fn (Carbon $from, Carbon $to) => DB::table('customer_order_items as coi')
            ->join('customer_orders as co', 'co.id', '=', 'coi.customer_order_id')
            ->join('product_skus as ps', 'ps.id', '=', 'coi.product_sku_id')
            ->where('co.brand_id', $brandId)
            ->where('co.organization_id', $organizationId)
            ->where('co.status', 'closed')
            ->whereBetween('co.created_at', [$from, $to])
            ->whereNull('co.deleted_at');

        $currentQuery = $baseQuery($r['from'], $r['to'])
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

        // Previous period quantities for trend
        $productIds = $current->keys()->all();
        $previous = $baseQuery($r['prev_from'], $r['prev_to'])
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

    // ── Recent Orders ─────────────────────────────────────────────────────────

    public function recentOrders(string $brandId, string $organizationId, int $limit = 5): Collection
    {
        return CustomerOrder::query()
            ->with(['table:id,code'])
            ->withCount('items')
            ->where('brand_id', $brandId)
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
