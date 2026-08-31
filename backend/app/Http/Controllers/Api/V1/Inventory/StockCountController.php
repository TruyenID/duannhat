<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\StockCountStoreRequest;
use App\Http\Requests\StockCountUpdateRequest;
use App\Http\Resources\StockCountResource;
use App\Models\StockCount;
use App\Services\Inventory\StockCountService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class StockCountController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly StockCountService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/stock-counts',
        summary: 'List stock counts',
        description: 'Returns a paginated list of stock count sessions. Filter by warehouse, status, scope, and date range.',
        tags: ['Stock Counts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'scope', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-created_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of stock counts',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StockCount')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockCount::class);

        $counts = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            // #1091 — branch context so date filters bind to the BRANCH's business day.
            'branch_id' => $request->attributes->get('shop_id'),
            'warehouse_id' => $request->input('warehouse_id'),
            'status' => $request->input('status'),
            'scope' => $request->input('scope'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'search' => $request->input('search'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return StockCountResource::collection($counts);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-counts',
        summary: 'Create a stock count',
        description: 'Creates a new stock count session for a warehouse. Starts in draft status.',
        tags: ['Stock Counts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'scope', type: 'string', nullable: true),
                    new OA\Property(property: 'count_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Stock count created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockCount')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(StockCountStoreRequest $request): JsonResponse
    {
        $this->authorize('create', StockCount::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['created_by_id'] = $request->user()->id;

        $count = $this->service->create($data);

        return (new StockCountResource($count))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/stock-counts/{stockCount}',
        summary: 'Get a stock count',
        description: 'Returns a single stock count with its items and variance data.',
        tags: ['Stock Counts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockCount', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock count details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockCount')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock count not found'),
        ]
    )]
    public function show(StockCount $stockCount): StockCountResource
    {
        $this->authorizeOrganization($stockCount);
        $this->authorize('view', $stockCount);

        return new StockCountResource(
            $this->service->findById($stockCount->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/stock-counts/{stockCount}',
        summary: 'Update a stock count',
        description: 'Updates a draft stock count session.',
        tags: ['Stock Counts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockCount', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'scope', type: 'string', nullable: true),
                new OA\Property(property: 'count_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Stock count updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockCount')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock count not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(StockCountUpdateRequest $request, StockCount $stockCount): StockCountResource
    {
        $this->authorizeOrganization($stockCount);
        $this->authorize('update', $stockCount);

        $count = $this->service->update($stockCount, $request->validated());

        return new StockCountResource($count);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/stock-counts/{stockCount}',
        summary: 'Delete a stock count',
        description: 'Soft-deletes a draft stock count session.',
        tags: ['Stock Counts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockCount', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Stock count deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock count not found'),
        ]
    )]
    public function destroy(StockCount $stockCount): JsonResponse
    {
        $this->authorizeOrganization($stockCount);
        $this->authorize('delete', $stockCount);

        $this->service->delete($stockCount);

        return response()->json(null, 204);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-counts/{stockCount}/start',
        summary: 'Start a stock count session',
        description: 'Transitions a stock count from draft to in-progress and freezes the expected quantities.',
        tags: ['Stock Counts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockCount', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock count started', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockCount')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock count not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function start(StockCount $stockCount): StockCountResource
    {
        $this->authorizeOrganization($stockCount);
        $this->authorize('update', $stockCount);

        $count = $this->service->start($stockCount);

        return new StockCountResource($count);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-counts/{stockCount}/add-items',
        summary: 'Add items to a stock count',
        description: 'Appends product SKU or material items to an active stock count session.',
        tags: ['Stock Counts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockCount', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['items'],
                properties: [
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid', nullable: true),
                            new OA\Property(property: 'material_id', type: 'string', format: 'uuid', nullable: true),
                            new OA\Property(property: 'unit', type: 'string', nullable: true),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Items added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockCount')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock count not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function addItems(Request $request, StockCount $stockCount): StockCountResource
    {
        $this->authorizeOrganization($stockCount);
        $this->authorize('update', $stockCount);

        $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_sku_id' => ['nullable', 'string', 'exists:product_skus,id'],
            'items.*.material_id' => ['nullable', 'string', 'exists:materials,id'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
        ]);

        $count = $this->service->addItems($stockCount, $request->input('items'));

        return new StockCountResource($count);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-counts/{stockCount}/update-items',
        summary: 'Update counted quantities on stock count items',
        description: 'Records counted quantities and optional notes for existing stock count items.',
        tags: ['Stock Counts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockCount', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['items'],
                properties: [
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                        type: 'object',
                        required: ['id', 'counted_quantity'],
                        properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'counted_quantity', type: 'number'),
                            new OA\Property(property: 'note', type: 'string', nullable: true),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Items updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockCount')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock count not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function updateItems(Request $request, StockCount $stockCount): StockCountResource
    {
        $this->authorizeOrganization($stockCount);
        $this->authorize('update', $stockCount);

        $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'string', 'exists:stock_count_items,id'],
            'items.*.counted_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string'],
        ]);

        $count = $this->service->updateItems($stockCount, $request->input('items'));

        return new StockCountResource($count);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-counts/{stockCount}/submit',
        summary: 'Submit stock count for approval',
        description: 'Transitions an in-progress stock count to pending approval.',
        tags: ['Stock Counts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockCount', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockCount')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock count not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function submit(StockCount $stockCount): StockCountResource
    {
        $this->authorizeOrganization($stockCount);
        $this->authorize('update', $stockCount);

        $count = $this->service->submit($stockCount);

        return new StockCountResource($count);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-counts/{stockCount}/approve',
        summary: 'Approve a stock count',
        description: 'Approves a submitted stock count and posts variance adjustments to stock levels.',
        tags: ['Stock Counts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockCount', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockCount')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock count not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function approve(Request $request, StockCount $stockCount): StockCountResource
    {
        $this->authorizeOrganization($stockCount);
        $this->authorize('approve', $stockCount);

        $count = $this->service->approve($stockCount, $request->user()->id);

        return new StockCountResource($count);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-counts/{stockCount}/cancel',
        summary: 'Cancel a stock count',
        description: 'Cancels an active stock count without applying variances.',
        tags: ['Stock Counts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockCount', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockCount')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock count not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function cancel(StockCount $stockCount): StockCountResource
    {
        $this->authorizeOrganization($stockCount);
        $this->authorize('update', $stockCount);

        $count = $this->service->cancel($stockCount);

        return new StockCountResource($count);
    }
}
