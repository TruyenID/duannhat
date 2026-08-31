<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\StockTransferReceiveRequest;
use App\Http\Requests\StockTransferStoreRequest;
use App\Http\Requests\StockTransferUpdateRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\StockTransfer;
use App\Services\Inventory\StockTransferService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class StockTransferController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly StockTransferService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/stock-transfers',
        summary: 'List stock transfers',
        description: 'Returns a paginated list of stock transfers between warehouses. Filter by source, destination, status, and date range.',
        tags: ['Stock Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'source_warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'destination_warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
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
                description: 'Paginated list of stock transfers',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StockTransfer')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockTransfer::class);

        $transfers = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            // #1091 — branch context so date filters bind to the BRANCH's business day.
            'branch_id' => $request->attributes->get('shop_id'),
            'source_warehouse_id' => $request->input('source_warehouse_id'),
            'destination_warehouse_id' => $request->input('destination_warehouse_id'),
            'status' => $request->input('status'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'search' => $request->input('search'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return StockTransferResource::collection($transfers);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-transfers',
        summary: 'Create a stock transfer',
        description: 'Creates a stock transfer between two warehouses. Starts in draft status.',
        tags: ['Stock Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['source_warehouse_id', 'destination_warehouse_id', 'items'],
                properties: [
                    new OA\Property(property: 'source_warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'destination_warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'reference', type: 'string', nullable: true),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                    new OA\Property(property: 'transfer_date', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Stock transfer created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransfer')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(StockTransferStoreRequest $request): JsonResponse
    {
        $this->authorize('create', StockTransfer::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['created_by_id'] = $request->user()->id;

        $transfer = $this->service->create($data);

        return (new StockTransferResource($transfer))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/stock-transfers/{stockTransfer}',
        summary: 'Get a stock transfer',
        description: 'Returns a single stock transfer with its items, source and destination warehouses.',
        tags: ['Stock Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock transfer details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransfer')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transfer not found'),
        ]
    )]
    public function show(StockTransfer $stockTransfer): StockTransferResource
    {
        $this->authorizeOrganization($stockTransfer);
        $this->authorize('view', $stockTransfer);

        return new StockTransferResource(
            $this->service->findById($stockTransfer->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/stock-transfers/{stockTransfer}',
        summary: 'Update a stock transfer',
        description: 'Updates a draft stock transfer. Only draft transfers may be edited.',
        tags: ['Stock Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'reference', type: 'string', nullable: true),
                new OA\Property(property: 'note', type: 'string', nullable: true),
                new OA\Property(property: 'transfer_date', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object'), nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Stock transfer updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransfer')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transfer not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(StockTransferUpdateRequest $request, StockTransfer $stockTransfer): StockTransferResource
    {
        $this->authorizeOrganization($stockTransfer);
        $this->authorize('update', $stockTransfer);

        $transfer = $this->service->update($stockTransfer, $request->validated());

        return new StockTransferResource($transfer);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/stock-transfers/{stockTransfer}',
        summary: 'Delete a stock transfer',
        description: 'Soft-deletes a draft stock transfer.',
        tags: ['Stock Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Stock transfer deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transfer not found'),
        ]
    )]
    public function destroy(StockTransfer $stockTransfer): JsonResponse
    {
        $this->authorizeOrganization($stockTransfer);
        $this->authorize('delete', $stockTransfer);

        $this->service->delete($stockTransfer);

        return response()->json(null, 204);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-transfers/{stockTransfer}/submit',
        summary: 'Submit a stock transfer for approval',
        description: 'Transitions a draft stock transfer to pending.',
        tags: ['Stock Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransfer')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transfer not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function submit(StockTransfer $stockTransfer): StockTransferResource
    {
        $this->authorizeOrganization($stockTransfer);
        $this->authorize('update', $stockTransfer);

        $transfer = $this->service->submit($stockTransfer);

        return new StockTransferResource($transfer);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-transfers/{stockTransfer}/approve',
        summary: 'Approve a stock transfer',
        description: 'Approves a pending stock transfer and marks stock as in transit. Records the approver.',
        tags: ['Stock Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransfer')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transfer not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function approve(Request $request, StockTransfer $stockTransfer): StockTransferResource
    {
        $this->authorizeOrganization($stockTransfer);
        $this->authorize('approve', $stockTransfer);

        $transfer = $this->service->approve($stockTransfer, $request->user()->id);

        return new StockTransferResource($transfer);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-transfers/{stockTransfer}/receive',
        summary: 'Receive an in-transit stock transfer',
        description: 'Records the receipt of a stock transfer at the destination warehouse. Optionally include actual received quantities per item.',
        tags: ['Stock Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object'), nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Received', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransfer')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transfer not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function receive(StockTransferReceiveRequest $request, StockTransfer $stockTransfer): StockTransferResource
    {
        $this->authorizeOrganization($stockTransfer);
        $this->authorize('update', $stockTransfer);

        // plan-040 TD.1: the FormRequest validates `items.*.received_quantity`
        // (gt:0 + lte sent_quantity) and item ownership. When the caller omits
        // `items` entirely the service falls back to received = sent.
        $transfer = $this->service->receive(
            $stockTransfer,
            $request->user()->id,
            $request->validated('items'),
        );

        return new StockTransferResource($transfer);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-transfers/{stockTransfer}/cancel',
        summary: 'Cancel a stock transfer',
        description: 'Cancels a draft, pending, or in-transit stock transfer.',
        tags: ['Stock Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransfer')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transfer not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function cancel(Request $request, StockTransfer $stockTransfer): StockTransferResource
    {
        $this->authorizeOrganization($stockTransfer);
        $this->authorize('update', $stockTransfer);

        // plan-040 TD.3: thread the user id so the reversal stock_in
        // auto-approves and the source warehouse is restored immediately.
        $transfer = $this->service->cancel($stockTransfer, $request->user()->id);

        return new StockTransferResource($transfer);
    }
}
