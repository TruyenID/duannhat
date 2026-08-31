<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\StockLevelUpdateRequest;
use App\Http\Resources\StockLevelResource;
use App\Http\Resources\StockMovementResource;
use App\Models\StockLevel;
use App\Services\Inventory\StockLevelService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class StockLevelController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly StockLevelService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/stock-levels',
        summary: 'List stock levels',
        description: 'Returns a paginated list of stock levels across warehouses. Supports search, warehouse filter, alert filter, sort and pagination.',
        tags: ['Stock Levels'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'alert_enabled', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(
                name: 'stock_status',
                in: 'query',
                required: false,
                description: 'Filter by computed status: `normal`, `low`, `out`. Computed from quantity vs min_stock.',
                schema: new OA\Schema(type: 'string', enum: ['normal', 'low', 'out'])
            ),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-updated_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of stock levels',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StockLevel')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockLevel::class);

        $levels = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'warehouse_id' => $request->input('warehouse_id'),
            'search' => $request->input('search'),
            'alert_enabled' => $request->has('alert_enabled') ? $request->boolean('alert_enabled') : null,
            'stock_status' => $request->input('stock_status'),
            'sort' => $request->input('sort', '-updated_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return StockLevelResource::collection($levels);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/stock-levels/{stockLevel}',
        summary: 'Get a stock level',
        description: 'Returns a single stock level record with related product/material and warehouse.',
        tags: ['Stock Levels'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockLevel', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock level details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockLevel')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock level not found'),
        ]
    )]
    public function show(StockLevel $stockLevel): StockLevelResource
    {
        $this->authorize('view', $stockLevel);

        return new StockLevelResource(
            $this->service->findById($stockLevel->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/stock-levels/{stockLevel}',
        summary: 'Update stock level alert settings',
        description: 'Updates alert thresholds and related settings on a stock level record.',
        tags: ['Stock Levels'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockLevel', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'min_stock', type: 'number', nullable: true, description: 'Low-stock threshold. Quantity at or below this triggers `stock_status=low`.'),
                new OA\Property(property: 'max_stock', type: 'number', nullable: true, description: 'Upper bound used by reorder workflows.'),
                new OA\Property(property: 'alert_enabled', type: 'boolean', description: 'Whether the alert pipeline reacts to threshold crossings on this row.'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Stock level updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockLevel')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock level not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(StockLevelUpdateRequest $request, StockLevel $stockLevel): StockLevelResource
    {
        $this->authorize('update', $stockLevel);

        $level = $this->service->update($stockLevel, $request->validated());

        return new StockLevelResource($level);
    }

    // =========================================================================
    //  Sub-resources
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/stock-levels/{stockLevel}/movements',
        summary: 'List stock movements for a level',
        description: 'Returns a paginated history of stock movements (in, out, adjustments) affecting this stock level.',
        tags: ['Stock Levels'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockLevel', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-created_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of movements',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock level not found'),
        ]
    )]
    public function movements(Request $request, StockLevel $stockLevel): AnonymousResourceCollection
    {
        $this->authorize('view', $stockLevel);

        $movements = $this->service->getMovements($stockLevel, [
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return StockMovementResource::collection($movements);
    }
}
