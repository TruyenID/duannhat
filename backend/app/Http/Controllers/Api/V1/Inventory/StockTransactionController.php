<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\StockTransactionStoreRequest;
use App\Http\Requests\StockTransactionUpdateRequest;
use App\Http\Resources\StockTransactionResource;
use App\Models\StockTransaction;
use App\Services\Inventory\StockTransactionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class StockTransactionController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly StockTransactionService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/stock-transactions',
        summary: 'List stock transactions',
        description: 'Returns a paginated list of stock transactions (stock-in, stock-out, adjustments). Supports filtering by warehouse, type, status, and date range.',
        tags: ['Stock Transactions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sub_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(
                name: 'status',
                in: 'query',
                required: false,
                description: 'Single value (e.g. `draft`), comma-separated list (`draft,pending`), or array form (`status[]=draft&status[]=pending`).',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-created_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of stock transactions',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/StockTransaction')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockTransaction::class);

        $transactions = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            // #1091 — branch context so date filters bind to the BRANCH's business day.
            'branch_id' => $request->attributes->get('shop_id'),
            'warehouse_id' => $request->input('warehouse_id'),
            'type' => $request->input('type'),
            'sub_type' => $request->input('sub_type'),
            'status' => $request->input('status'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'search' => $request->input('search'),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return StockTransactionResource::collection($transactions);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-transactions',
        summary: 'Create a stock transaction',
        description: 'Creates a new stock transaction (stock-in, stock-out, or adjustment). Starts in draft status.',
        tags: ['Stock Transactions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'type', 'items'],
                properties: [
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'sub_type', type: 'string', nullable: true),
                    new OA\Property(property: 'reference', type: 'string', nullable: true),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                    new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Stock transaction created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransaction')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(StockTransactionStoreRequest $request): JsonResponse
    {
        $this->authorize('create', StockTransaction::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['created_by_id'] = $request->user()->id;

        $transaction = $this->service->create($data);

        return (new StockTransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/stock-transactions/{stockTransaction}',
        summary: 'Get a stock transaction',
        description: 'Returns a single stock transaction with its items and relations.',
        tags: ['Stock Transactions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransaction', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock transaction details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransaction')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transaction not found'),
        ]
    )]
    public function show(StockTransaction $stockTransaction): StockTransactionResource
    {
        $this->authorizeOrganization($stockTransaction);
        $this->authorize('view', $stockTransaction);

        return new StockTransactionResource(
            $this->service->findById($stockTransaction->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/stock-transactions/{stockTransaction}',
        summary: 'Update a stock transaction',
        description: 'Updates a draft stock transaction. Only draft transactions may be edited.',
        tags: ['Stock Transactions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransaction', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'reference', type: 'string', nullable: true),
                new OA\Property(property: 'note', type: 'string', nullable: true),
                new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object'), nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Stock transaction updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransaction')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transaction not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(StockTransactionUpdateRequest $request, StockTransaction $stockTransaction): StockTransactionResource
    {
        $this->authorizeOrganization($stockTransaction);
        $this->authorize('update', $stockTransaction);

        $transaction = $this->service->update($stockTransaction, $request->validated());

        return new StockTransactionResource($transaction);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/stock-transactions/{stockTransaction}',
        summary: 'Delete a stock transaction',
        description: 'Soft-deletes a draft stock transaction. Posted transactions cannot be deleted.',
        tags: ['Stock Transactions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransaction', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Stock transaction deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transaction not found'),
        ]
    )]
    public function destroy(StockTransaction $stockTransaction): JsonResponse
    {
        $this->authorizeOrganization($stockTransaction);
        $this->authorize('delete', $stockTransaction);

        $this->service->delete($stockTransaction);

        return response()->json(null, 204);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-transactions/{stockTransaction}/submit',
        summary: 'Submit a stock transaction for approval',
        description: 'Transitions a draft stock transaction to pending. Requires draft state.',
        tags: ['Stock Transactions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransaction', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransaction')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transaction not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function submit(StockTransaction $stockTransaction): StockTransactionResource
    {
        $this->authorizeOrganization($stockTransaction);
        $this->authorize('update', $stockTransaction);

        $transaction = $this->service->submit($stockTransaction);

        return new StockTransactionResource($transaction);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-transactions/{stockTransaction}/approve',
        summary: 'Approve a stock transaction',
        description: 'Approves a pending stock transaction and posts it to stock levels. Records approver and timestamp.',
        tags: ['Stock Transactions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransaction', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransaction')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transaction not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function approve(Request $request, StockTransaction $stockTransaction): StockTransactionResource
    {
        $this->authorizeOrganization($stockTransaction);
        $this->authorize('approve', $stockTransaction);

        $transaction = $this->service->approve($stockTransaction, $request->user()->id);

        return new StockTransactionResource($transaction);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/stock-transactions/{stockTransaction}/cancel',
        summary: 'Cancel a stock transaction',
        description: 'Cancels a draft or pending stock transaction. Posted transactions cannot be cancelled directly.',
        tags: ['Stock Transactions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stockTransaction', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockTransaction')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Stock transaction not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function cancel(StockTransaction $stockTransaction): StockTransactionResource
    {
        $this->authorizeOrganization($stockTransaction);
        $this->authorize('update', $stockTransaction);

        $transaction = $this->service->cancel($stockTransaction);

        return new StockTransactionResource($transaction);
    }
}
