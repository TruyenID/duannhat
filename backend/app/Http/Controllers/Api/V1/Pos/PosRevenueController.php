<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Services\Pos\PosRevenueService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/pos/revenue/summary
 *
 * Daily / monthly revenue aggregates for the resolved POS shop. Powers the
 * "Doanh thu" (Revenue) screen in pos-web. The exact same shape is served
 * by the workstation LAN handler so the client doesn't branch on transport.
 */
class PosRevenueController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PosRevenueService $service,
    ) {}

    public function byProduct(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $branchId = $request->attributes->get('shop_id');
        $organizationId = $request->attributes->get('organization_id');

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'level' => ['nullable', Rule::in([
                PosRevenueService::PRODUCT_LEVEL_PRODUCT,
                PosRevenueService::PRODUCT_LEVEL_SKU,
            ])],
            'category_id' => ['nullable', 'uuid'],
            'sort' => ['nullable', Rule::in([
                PosRevenueService::PRODUCT_SORT_REVENUE,
                PosRevenueService::PRODUCT_SORT_QUANTITY,
                PosRevenueService::PRODUCT_SORT_SHARE,
            ])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        [$from, $to] = $this->resolveWindow(
            PosRevenueService::GRANULARITY_DAY,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        $data = $this->service->byProduct(
            branchId: $branchId,
            organizationId: $organizationId,
            from: $from,
            to: $to,
            level: $validated['level'] ?? PosRevenueService::PRODUCT_LEVEL_PRODUCT,
            categoryId: $validated['category_id'] ?? null,
            sort: $validated['sort'] ?? PosRevenueService::PRODUCT_SORT_REVENUE,
            page: $validated['page'] ?? 1,
            perPage: $validated['per_page'] ?? 25,
            brandId: $request->attributes->get('brand_id'),
        );

        return response()->json(['data' => $data]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $branchId = $request->attributes->get('shop_id');
        $organizationId = $request->attributes->get('organization_id');

        $validated = $request->validate([
            'granularity' => ['nullable', Rule::in([
                PosRevenueService::GRANULARITY_DAY,
                PosRevenueService::GRANULARITY_MONTH,
                PosRevenueService::GRANULARITY_YEAR,
            ])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $granularity = $validated['granularity'] ?? PosRevenueService::GRANULARITY_DAY;

        [$from, $to] = $this->resolveWindow($granularity, $validated['from'] ?? null, $validated['to'] ?? null);

        $data = $this->service->summary(
            branchId: $branchId,
            organizationId: $organizationId,
            granularity: $granularity,
            from: $from,
            to: $to,
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/pos/revenue/voids
     *
     * Cancellation analytics (whole-order + per-item voids) for the reports
     * screen. Same window contract as summary(); shape mirrors the workstation
     * LAN handler so the client doesn't branch on transport.
     */
    public function voids(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $branchId = $request->attributes->get('shop_id');
        $organizationId = $request->attributes->get('organization_id');

        $validated = $request->validate([
            'granularity' => ['nullable', Rule::in([
                PosRevenueService::GRANULARITY_DAY,
                PosRevenueService::GRANULARITY_MONTH,
                PosRevenueService::GRANULARITY_YEAR,
            ])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $granularity = $validated['granularity'] ?? PosRevenueService::GRANULARITY_DAY;

        [$from, $to] = $this->resolveWindow($granularity, $validated['from'] ?? null, $validated['to'] ?? null);

        $data = $this->service->voids(
            branchId: $branchId,
            organizationId: $organizationId,
            granularity: $granularity,
            from: $from,
            to: $to,
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/pos/revenue/void-events
     *
     * Flat, paginated log of individual cancellations (which order, when, why,
     * how much) — the drill-down behind the aggregate voids() report. Same
     * window contract; shape mirrors the workstation LAN handler.
     */
    public function voidEvents(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $branchId = $request->attributes->get('shop_id');
        $organizationId = $request->attributes->get('organization_id');

        $validated = $request->validate([
            'granularity' => ['nullable', Rule::in([
                PosRevenueService::GRANULARITY_DAY,
                PosRevenueService::GRANULARITY_MONTH,
                PosRevenueService::GRANULARITY_YEAR,
            ])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(['all', 'order', 'item'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $granularity = $validated['granularity'] ?? PosRevenueService::GRANULARITY_DAY;

        [$from, $to] = $this->resolveWindow($granularity, $validated['from'] ?? null, $validated['to'] ?? null);

        $data = $this->service->voidEvents(
            branchId: $branchId,
            organizationId: $organizationId,
            from: $from,
            to: $to,
            type: $validated['type'] ?? 'all',
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 20),
        );

        return response()->json(['data' => $data]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveWindow(string $granularity, ?string $from, ?string $to): array
    {
        $now = CarbonImmutable::now();

        if ($granularity === PosRevenueService::GRANULARITY_YEAR) {
            $toC = $to ? CarbonImmutable::parse($to)->endOfYear() : $now->endOfYear();
            // 5-year trailing window by default (current year + 4 prior).
            $fromC = $from
                ? CarbonImmutable::parse($from)->startOfYear()
                : $toC->subYears(4)->startOfYear();
        } elseif ($granularity === PosRevenueService::GRANULARITY_MONTH) {
            $toC = $to ? CarbonImmutable::parse($to)->endOfMonth() : $now->endOfMonth();
            // #1424 — `startOfMonth()` TRƯỚC, `subMonths()` SAU. `$toC` là CUỐI
            // tháng, nên trừ tháng từ ngày 31 sẽ tràn sang tháng sau khi tháng đích
            // ngắn hơn, và cửa sổ 12 tháng lặng lẽ còn 11:
            //   2026-03-31 → subMonths(11) → 2025-04-31 (không có) → 2025-05-01
            // Báo cáo không kêu lỗi, chỉ ra một con số nhỏ hơn — và theo LỊCH chứ
            // không ngẫu nhiên, nên nó đúng/sai luân phiên theo độ dài tháng.
            $fromC = $from
                ? CarbonImmutable::parse($from)->startOfMonth()
                : $toC->startOfMonth()->subMonths(11);
        } else {
            $toC = $to ? CarbonImmutable::parse($to)->endOfDay() : $now->endOfDay();
            $fromC = $from ? CarbonImmutable::parse($from)->startOfDay() : $now->startOfMonth();
        }

        // Hard cap so a runaway filter can't scan the whole orders table.
        //   day   → 366 days
        //   month → ~36 months (1116 days)
        //   year  → 20 years
        $maxDays = match ($granularity) {
            PosRevenueService::GRANULARITY_YEAR => 20 * 366,
            PosRevenueService::GRANULARITY_MONTH => 36 * 31,
            default => 366,
        };
        if ($fromC->diffInDays($toC) > $maxDays) {
            $fromC = $toC->subDays($maxDays)->startOfDay();
        }

        return [$fromC, $toC];
    }
}
