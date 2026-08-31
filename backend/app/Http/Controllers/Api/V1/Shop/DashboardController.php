<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\Branch;
use App\Models\BranchReview;
use App\Models\CustomerOrder;
use App\Services\Dashboard\ShopDashboardService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ShopDashboardService $service,
    ) {}

    // ── KPI Cards ─────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/dashboard/kpis',
        summary: 'Shop KPI summary cards (revenue, orders, table occupancy, low stock)',
        description: 'Returns 4 KPI cards for today vs yesterday: revenue, orders, table occupancy (occupied/total), and low-stock item count.',
        tags: ['Shop Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
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
                    new OA\Property(property: 'table_occupancy', type: 'object', properties: [
                        new OA\Property(property: 'occupied', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                    ]),
                    new OA\Property(property: 'low_stock', type: 'object', properties: [
                        new OA\Property(property: 'value', type: 'integer'),
                    ]),
                ]),
            ])),
        ]
    )]
    public function kpis(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $data = $this->service->kpis(
            branchId: $request->attributes->get('shop_id'),
            organizationId: $this->getOrganizationId(),
        );

        return response()->json(['data' => $data]);
    }

    // ── Revenue Trend ─────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/dashboard/revenue-trend',
        summary: 'Daily revenue and order count for the last 7 days',
        description: 'Returns one entry per day covering today and the 6 preceding days.',
        tags: ['Shop Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trend data', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'day', type: 'string', example: '2026-04-21'),
                    new OA\Property(property: 'revenue', type: 'integer'),
                    new OA\Property(property: 'orders', type: 'integer'),
                ])),
            ])),
        ]
    )]
    public function revenueTrend(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $data = $this->service->revenueTrend(
            branchId: $request->attributes->get('shop_id'),
            organizationId: $this->getOrganizationId(),
        );

        return response()->json(['data' => $data]);
    }

    // ── Table Status ──────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/dashboard/table-status',
        summary: 'Real-time table count grouped by status',
        description: 'Returns the count of active tables for each status (free, occupied, reserved, cleaning, out_of_service).',
        tags: ['Shop Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Table status', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['free', 'occupied', 'reserved', 'cleaning', 'out_of_service']),
                    new OA\Property(property: 'count', type: 'integer'),
                ])),
            ])),
        ]
    )]
    public function tableStatus(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $data = $this->service->tableStatus(
            branchId: $request->attributes->get('shop_id'),
        );

        return response()->json(['data' => $data]);
    }

    // ── Top Items ─────────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/dashboard/top-items',
        summary: 'Top N products sold today ranked by units sold',
        description: 'Returns products sorted by quantity sold today. Trend is up/down compared to the same product yesterday.',
        tags: ['Shop Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 5, maximum: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Top items', content: new OA\JsonContent(properties: [
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
    public function topItems(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $data = $this->service->topItems(
            branchId: $request->attributes->get('shop_id'),
            organizationId: $this->getOrganizationId(),
            limit: min($request->integer('limit', 5), 20),
        );

        return response()->json(['data' => $data]);
    }

    // ── Production Queue ──────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/dashboard/production-queue',
        summary: 'Open order items pending or in progress today',
        description: 'Returns order items with status pending/preparing/ready from open orders today, sorted by order creation time.',
        tags: ['Shop Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10, maximum: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Production queue', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'order_code', type: 'string'),
                    new OA\Property(property: 'product_name', type: 'string'),
                    new OA\Property(property: 'quantity', type: 'integer'),
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_progress']),
                ])),
            ])),
        ]
    )]
    public function productionQueue(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $data = $this->service->productionQueue(
            branchId: $request->attributes->get('shop_id'),
            organizationId: $this->getOrganizationId(),
            limit: min($request->integer('limit', 10), 50),
        );

        return response()->json(['data' => $data]);
    }

    // ── Recent Orders ─────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/dashboard/recent-orders',
        summary: 'Most recent orders for this shop',
        description: 'Returns the latest N orders for the resolved branch.',
        tags: ['Shop Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
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
            branchId: $request->attributes->get('shop_id'),
            organizationId: $this->getOrganizationId(),
            limit: min($request->integer('limit', 5), 20),
        );

        return response()->json(['data' => $data]);
    }

    // ── Branch Reviews ───────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/dashboard/branch-reviews',
        summary: 'Branch review stats and recent reviews',
        description: 'Returns aggregate rating stats and the most recent reviews for this branch.',
        tags: ['Shop Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 5, maximum: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Branch review stats', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'avg_rating', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'total_count', type: 'integer'),
                    new OA\Property(property: 'recent', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'rating', type: 'integer'),
                        new OA\Property(property: 'service_rating', type: 'integer', nullable: true),
                        new OA\Property(property: 'staff_rating', type: 'integer', nullable: true),
                        new OA\Property(property: 'space_rating', type: 'integer', nullable: true),
                        new OA\Property(property: 'highlights', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'comment', type: 'string', nullable: true),
                        new OA\Property(property: 'photos', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'url', type: 'string', nullable: true),
                        ])),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    ])),
                ]),
            ])),
        ]
    )]
    public function branchReviews(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerOrder::class);

        $branchId = $request->attributes->get('shop_id');
        $branch = Branch::find($branchId);
        $limit = min($request->integer('limit', 5), 20);

        $recent = BranchReview::where('branch_id', $branchId)
            ->with('photos')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (BranchReview $r) => $r->toReviewArray());

        return response()->json([
            'data' => [
                'avg_rating' => $branch?->review_avg_rating ? (float) $branch->review_avg_rating : null,
                'total_count' => (int) ($branch?->review_total_count ?? 0),
                'recent' => $recent,
            ],
        ]);
    }
}
