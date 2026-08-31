<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ProductionOrderStoreRequest;
use App\Http\Requests\ProductionOrderUpdateRequest;
use App\Http\Resources\ProductionOrderResource;
use App\Models\ProductionOrder;
use App\Services\Inventory\ProductionOrderService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class ProductionOrderController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ProductionOrderService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/production-orders',
        summary: 'List production orders',
        description: 'Returns a paginated list of production orders. Filter by warehouse, status and date range.',
        tags: ['Production Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-created_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of production orders',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductionOrder')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProductionOrder::class);

        $orders = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            // #1091 — branch context so date filters bind to the BRANCH's business day.
            'branch_id' => $request->attributes->get('shop_id'),
            'warehouse_id' => $request->input('warehouse_id'),
            'status' => $request->input('status'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'search' => $request->input('search'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return ProductionOrderResource::collection($orders);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/production-orders',
        summary: 'Create a production order',
        description: 'Creates a new production order for a product. Starts in draft status.',
        tags: ['Production Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id', 'warehouse_id', 'planned_quantity'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'planned_quantity', type: 'number'),
                    new OA\Property(property: 'planned_date', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Production order created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductionOrder')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(ProductionOrderStoreRequest $request): JsonResponse
    {
        $this->authorize('create', ProductionOrder::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['created_by_id'] = $request->user()->id;

        $order = $this->service->create($data);

        return (new ProductionOrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/production-orders/{productionOrder}',
        summary: 'Get a production order',
        description: 'Returns a single production order with its items and consumption details.',
        tags: ['Production Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productionOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Production order details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductionOrder')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Production order not found'),
        ]
    )]
    public function show(ProductionOrder $productionOrder): ProductionOrderResource
    {
        $this->authorizeOrganization($productionOrder);
        $this->authorize('view', $productionOrder);

        return new ProductionOrderResource(
            $this->service->findById($productionOrder->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/production-orders/{productionOrder}',
        summary: 'Update a production order',
        description: 'Updates a draft production order.',
        tags: ['Production Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productionOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'planned_quantity', type: 'number', nullable: true),
                new OA\Property(property: 'planned_date', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Production order updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductionOrder')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Production order not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(ProductionOrderUpdateRequest $request, ProductionOrder $productionOrder): ProductionOrderResource
    {
        $this->authorizeOrganization($productionOrder);
        $this->authorize('update', $productionOrder);

        $order = $this->service->update($productionOrder, $request->validated());

        return new ProductionOrderResource($order);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/production-orders/{productionOrder}',
        summary: 'Delete a production order',
        description: 'Soft-deletes a draft production order.',
        tags: ['Production Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productionOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Production order deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Production order not found'),
        ]
    )]
    public function destroy(ProductionOrder $productionOrder): JsonResponse
    {
        $this->authorizeOrganization($productionOrder);
        $this->authorize('delete', $productionOrder);

        $this->service->delete($productionOrder);

        return response()->json(null, 204);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/production-orders/{productionOrder}/submit',
        summary: 'Submit a production order for approval',
        description: 'Transitions a draft production order to pending.',
        tags: ['Production Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productionOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductionOrder')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Production order not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function submit(ProductionOrder $productionOrder): ProductionOrderResource
    {
        $this->authorizeOrganization($productionOrder);
        $this->authorize('update', $productionOrder);

        $order = $this->service->submit($productionOrder);

        return new ProductionOrderResource($order);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/production-orders/{productionOrder}/approve',
        summary: 'Approve a production order',
        description: 'Approves a pending production order so it can be started.',
        tags: ['Production Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productionOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductionOrder')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Production order not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function approve(Request $request, ProductionOrder $productionOrder): ProductionOrderResource
    {
        $this->authorizeOrganization($productionOrder);
        $this->authorize('approve', $productionOrder);

        $order = $this->service->approve($productionOrder, $request->user()->id);

        return new ProductionOrderResource($order);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/production-orders/{productionOrder}/start',
        summary: 'Start a production order',
        description: 'Transitions an approved production order to in-progress and consumes component stock.',
        tags: ['Production Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productionOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Started', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductionOrder')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Production order not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function start(Request $request, ProductionOrder $productionOrder): ProductionOrderResource
    {
        $this->authorizeOrganization($productionOrder);
        $this->authorize('update', $productionOrder);

        $order = $this->service->start($productionOrder, $request->user()->id);

        return new ProductionOrderResource($order);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/production-orders/{productionOrder}/complete',
        summary: 'Complete a production order',
        description: 'Marks an in-progress production order as complete and posts the actual quantity to finished goods stock.',
        tags: ['Production Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productionOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'actual_quantity', type: 'number', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductionOrder')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Production order not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function complete(Request $request, ProductionOrder $productionOrder): ProductionOrderResource
    {
        $this->authorizeOrganization($productionOrder);
        $this->authorize('update', $productionOrder);

        $order = $this->service->complete(
            $productionOrder,
            $request->user()->id,
            $request->input('actual_quantity'),
        );

        return new ProductionOrderResource($order);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/production-orders/{productionOrder}/cancel',
        summary: 'Cancel a production order',
        description: 'Cancels a production order. Reverts any component consumption when applicable.',
        tags: ['Production Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productionOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductionOrder')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Production order not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function cancel(Request $request, ProductionOrder $productionOrder): ProductionOrderResource
    {
        $this->authorizeOrganization($productionOrder);
        $this->authorize('update', $productionOrder);

        // plan-040 TD.4: thread the user id so the reversal stock_in
        // auto-approves and consumed materials are restored immediately.
        $order = $this->service->cancel($productionOrder, $request->user()->id);

        return new ProductionOrderResource($order);
    }
}
