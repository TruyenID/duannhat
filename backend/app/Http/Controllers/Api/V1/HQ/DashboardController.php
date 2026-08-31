<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\CustomerOrder;
use App\Services\Dashboard\DashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly DashboardService $service,
    ) {}

    // ── KPI Cards ─────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/dashboard/kpis',
        summary: 'KPI summary cards (revenue, orders, products, shops)',
        description: 'Returns 4 KPI cards with value and delta % vs the previous period.',
        tags: ['HQ Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'period', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['month', 'week', 'year'], default: 'month')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'KPI data', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'revenue', type: 'object', properties: [
                        new OA\Property(property: 'value', type: 'integer'),
                        new OA\Property(property: 'delta_pct', type: 'integer'),
                    ]),
                    new OA\Property(property: 'orders', type: 'object', properties: [
                        new OA\Property(property: 'value', type: 'integer'),
                        new OA\Property(property: 'delta_pct', type: 'integer'),
                    ]),
                    new OA\Property(property: 'products', type: 'object', properties: [
                        new OA\Property(property: 'value', type: 'integer'),
                        new OA\Property(property: 'delta_pct', type: 'integer'),
                    ]),
                    new OA\Property(property: 'shops', type: 'object', properties: [
                        new OA\Property(property: 'value', type: 'integer'),
                        new OA\Property(property: 'delta_pct', type: 'integer'),
                    ]),
                ]),
            ])),
        ]
    )]
    public function kpis(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $data = $this->service->kpis(
            brandId: $request->attributes->get('brand_id'),
            organizationId: $this->getOrganizationId(),
            period: $request->input('period', 'month'),
        );

        return response()->json(['data' => $data]);
    }

    // ── Revenue Chart ─────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/dashboard/revenue-chart',
        summary: 'Revenue and order count grouped by month or week',
        description: 'Returns time-series data for the revenue area chart and orders bar chart. Defaults to the last 6 months.',
        tags: ['HQ Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'group_by', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['month', 'week', 'year'], default: 'month')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chart data', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'period', type: 'string', example: '2026-04'),
                    new OA\Property(property: 'revenue', type: 'integer'),
                    new OA\Property(property: 'orders', type: 'integer'),
                ])),
            ])),
        ]
    )]
    public function revenueChart(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $groupBy = $request->input('group_by', 'month');
        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->input('date_from'))
            // #1424 — mốc đầu tháng TRƯỚC, trừ tháng SAU (Carbon tràn tháng).
            : now()->startOfMonth()->subMonths(6);
        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->input('date_to'))
            : now()->endOfMonth();

        $data = $this->service->revenueChart(
            brandId: $request->attributes->get('brand_id'),
            organizationId: $this->getOrganizationId(),
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            groupBy: $groupBy,
        );

        return response()->json(['data' => $data]);
    }

    // ── Category Sales ────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/dashboard/category-sales',
        summary: 'Revenue breakdown by product category (pie chart)',
        description: 'Returns revenue percentage per category for the selected period.',
        tags: ['HQ Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'period', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['month', 'week', 'year'], default: 'month')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category breakdown', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'category_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'category_name', type: 'string'),
                    new OA\Property(property: 'revenue', type: 'integer'),
                    new OA\Property(property: 'percentage', type: 'number', format: 'float'),
                ])),
            ])),
        ]
    )]
    public function categorySales(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $data = $this->service->categorySales(
            brandId: $request->attributes->get('brand_id'),
            organizationId: $this->getOrganizationId(),
            period: $request->input('period', 'month'),
        );

        return response()->json(['data' => $data]);
    }

    // ── Shop Performance ──────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/dashboard/shop-performance',
        summary: 'Revenue vs auto-calculated target per shop/branch',
        description: 'Target is computed as the 3-month rolling average revenue × 1.1. Returns progress bar data for each branch.',
        tags: ['HQ Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'period', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['month', 'week', 'year'], default: 'month')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Shop performance', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'branch_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'branch_slug', type: 'string'),
                    new OA\Property(property: 'branch_name', type: 'string'),
                    new OA\Property(property: 'revenue', type: 'integer'),
                    new OA\Property(property: 'target', type: 'integer'),
                ])),
            ])),
        ]
    )]
    public function shopPerformance(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $data = $this->service->shopPerformance(
            brandId: $request->attributes->get('brand_id'),
            organizationId: $this->getOrganizationId(),
            period: $request->input('period', 'month'),
        );

        return response()->json(['data' => $data]);
    }

    // ── Top Products ──────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/dashboard/top-products',
        summary: 'Top N products ranked by units sold',
        description: 'Returns products sorted by quantity sold descending. Trend is up/down compared to the previous period.',
        tags: ['HQ Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'period', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['month', 'week', 'year'], default: 'month')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 5, maximum: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Top products', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'product_name', type: 'string'),
                    new OA\Property(property: 'category_name', type: 'string'),
                    new OA\Property(property: 'sold', type: 'integer'),
                    new OA\Property(property: 'revenue', type: 'integer'),
                    new OA\Property(property: 'trend', type: 'string', enum: ['up', 'down']),
                ])),
            ])),
        ]
    )]
    public function topProducts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $data = $this->service->topProducts(
            brandId: $request->attributes->get('brand_id'),
            organizationId: $this->getOrganizationId(),
            period: $request->input('period', 'month'),
            limit: min($request->integer('limit', 5), 20),
        );

        return response()->json(['data' => $data]);
    }

    // ── Recent Orders ─────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/dashboard/recent-orders',
        summary: 'Most recent orders across all branches',
        description: 'Returns the latest N orders. Table code may be null for completed orders where the table has been freed.',
        tags: ['HQ Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 5, maximum: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Recent orders', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'order_code', type: 'string'),
                    new OA\Property(property: 'table_code', type: 'string', nullable: true),
                    new OA\Property(property: 'items_count', type: 'integer'),
                    new OA\Property(property: 'total_amount', type: 'integer'),
                    new OA\Property(property: 'status', type: 'string', enum: ['completed', 'in_progress', 'cancelled']),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                ])),
            ])),
        ]
    )]
    public function recentOrders(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $data = $this->service->recentOrders(
            brandId: $request->attributes->get('brand_id'),
            organizationId: $this->getOrganizationId(),
            limit: min($request->integer('limit', 5), 20),
        );

        return response()->json(['data' => $data]);
    }
}
