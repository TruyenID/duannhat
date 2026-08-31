<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\MaterialBatchStoreRequest;
use App\Http\Requests\MaterialBatchUpdateRequest;
use App\Http\Resources\MaterialBatchResource;
use App\Models\MaterialBatch;
use App\Services\Inventory\MaterialBatchService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class MaterialBatchController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly MaterialBatchService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/material-batches',
        summary: 'List material batches',
        description: 'Returns a paginated list of material production batches. Filter by warehouse, status, and date range.',
        tags: ['Material Batches'],
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
                description: 'Paginated list of material batches',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MaterialBatch')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MaterialBatch::class);

        $batches = $this->service->list([
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

        return MaterialBatchResource::collection($batches);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-batches',
        summary: 'Create a material batch',
        description: 'Creates a new material production batch. Starts in draft status.',
        tags: ['Material Batches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['material_id', 'warehouse_id', 'planned_yield'],
                properties: [
                    new OA\Property(property: 'material_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'recipe_id', type: 'string', format: 'uuid', nullable: true, description: 'Snapshotted Recipe. Optional — server falls back to latest approved recipe.'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'planned_yield', type: 'number'),
                    new OA\Property(property: 'planned_date', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                    new OA\Property(property: 'components', type: 'array', items: new OA\Items(type: 'object'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Material batch created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MaterialBatch')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(MaterialBatchStoreRequest $request): JsonResponse
    {
        $this->authorize('create', MaterialBatch::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['created_by_id'] = $request->user()->id;

        $batch = $this->service->create($data);

        return (new MaterialBatchResource($batch))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/material-batches/{materialBatch}',
        summary: 'Get a material batch',
        description: 'Returns a single material batch with component consumption details.',
        tags: ['Material Batches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'materialBatch', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Material batch details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MaterialBatch')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Material batch not found'),
        ]
    )]
    public function show(MaterialBatch $materialBatch): MaterialBatchResource
    {
        $this->authorizeOrganization($materialBatch);
        $this->authorize('view', $materialBatch);

        return new MaterialBatchResource(
            $this->service->findById($materialBatch->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/material-batches/{materialBatch}',
        summary: 'Update a material batch',
        description: 'Updates a draft material batch.',
        tags: ['Material Batches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'materialBatch', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'planned_yield', type: 'number', nullable: true),
                new OA\Property(property: 'planned_date', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'note', type: 'string', nullable: true),
                new OA\Property(property: 'components', type: 'array', items: new OA\Items(type: 'object'), nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Material batch updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MaterialBatch')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Material batch not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(MaterialBatchUpdateRequest $request, MaterialBatch $materialBatch): MaterialBatchResource
    {
        $this->authorizeOrganization($materialBatch);
        $this->authorize('update', $materialBatch);

        $batch = $this->service->update($materialBatch, $request->validated());

        return new MaterialBatchResource($batch);
    }

    #[OA\Delete(
        path: '/api/v1/shops/{shopSlug}/material-batches/{materialBatch}',
        summary: 'Delete a material batch',
        description: 'Soft-deletes a draft material batch.',
        tags: ['Material Batches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'materialBatch', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Material batch deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Material batch not found'),
        ]
    )]
    public function destroy(MaterialBatch $materialBatch): JsonResponse
    {
        $this->authorizeOrganization($materialBatch);
        $this->authorize('delete', $materialBatch);

        $this->service->delete($materialBatch);

        return response()->json(null, 204);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-batches/{materialBatch}/submit',
        summary: 'Submit a material batch for approval',
        description: 'Transitions a draft material batch to pending.',
        tags: ['Material Batches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'materialBatch', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MaterialBatch')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Material batch not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function submit(MaterialBatch $materialBatch): MaterialBatchResource
    {
        $this->authorizeOrganization($materialBatch);
        $this->authorize('update', $materialBatch);

        $batch = $this->service->submit($materialBatch);

        return new MaterialBatchResource($batch);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-batches/{materialBatch}/approve',
        summary: 'Approve a material batch',
        description: 'Approves a pending material batch so it can be started.',
        tags: ['Material Batches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'materialBatch', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MaterialBatch')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Material batch not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function approve(Request $request, MaterialBatch $materialBatch): MaterialBatchResource
    {
        $this->authorizeOrganization($materialBatch);
        $this->authorize('approve', $materialBatch);

        $batch = $this->service->approve($materialBatch, $request->user()->id);

        return new MaterialBatchResource($batch);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-batches/{materialBatch}/start',
        summary: 'Start a material batch',
        description: 'Transitions an approved material batch to in-progress and consumes the component stock.',
        tags: ['Material Batches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'materialBatch', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Started', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MaterialBatch')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Material batch not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function start(MaterialBatch $materialBatch): MaterialBatchResource
    {
        $this->authorizeOrganization($materialBatch);
        $this->authorize('update', $materialBatch);

        $batch = $this->service->start($materialBatch);

        return new MaterialBatchResource($batch);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-batches/{materialBatch}/complete',
        summary: 'Complete a material batch',
        description: 'Marks an in-progress batch as complete and posts the actual yield to stock levels.',
        tags: ['Material Batches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'materialBatch', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'actual_yield', type: 'number', nullable: true),
                new OA\Property(property: 'yield_variance_reason', type: 'string', nullable: true, description: 'Required when |actual - planned| / planned * 100 exceeds recipe.yield_variance_tolerance_pct.'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MaterialBatch')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Material batch not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function complete(Request $request, MaterialBatch $materialBatch): MaterialBatchResource
    {
        $this->authorizeOrganization($materialBatch);
        $this->authorize('update', $materialBatch);

        $request->validate([
            'actual_yield' => ['nullable', 'numeric', 'min:0'],
            'yield_variance_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $batch = $this->service->complete(
            $materialBatch,
            $request->user()->id,
            $request->input('actual_yield'),
            $request->input('yield_variance_reason'),
        );

        return new MaterialBatchResource($batch);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-batches/{materialBatch}/cancel',
        summary: 'Cancel a material batch',
        description: 'Cancels a draft, pending, or in-progress material batch. Reverts component consumption when applicable.',
        tags: ['Material Batches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'materialBatch', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MaterialBatch')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Material batch not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function cancel(Request $request, MaterialBatch $materialBatch): MaterialBatchResource
    {
        $this->authorizeOrganization($materialBatch);
        $this->authorize('update', $materialBatch);

        // plan-040 TD.5 (M1): thread the user id so the reversal stock_in
        // auto-approves (even when the warehouse has auto_approve_stock_in=false)
        // and the consumed lots are restored.
        $batch = $this->service->cancel($materialBatch, $request->user()->id);

        return new MaterialBatchResource($batch);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/material-batches/{materialBatch}/cost-breakdown',
        summary: 'Cost breakdown by consumed lot for a completed batch',
        description: 'Plan-017 Tier 1.B — returns the per-consumed-lot cost decomposition that powers the production batch detail UI panel. NULL when the batch has not yet been completed.',
        tags: ['MaterialBatches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'materialBatch', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cost breakdown'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function costBreakdown(MaterialBatch $materialBatch): JsonResponse
    {
        $this->authorizeOrganization($materialBatch);
        $this->authorize('view', $materialBatch);

        return response()->json([
            'data' => $this->service->getCostBreakdown($materialBatch),
        ]);
    }
}
