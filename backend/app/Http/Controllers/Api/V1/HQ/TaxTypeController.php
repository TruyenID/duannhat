<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\TaxTypeStoreRequest;
use App\Http\Requests\TaxTypeUpdateRequest;
use App\Http\Resources\TaxTypeResource;
use App\Models\TaxType;
use App\Services\Pos\TillSessionService;
use App\Services\Tax\TaxTypeService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/tax-types/bulk-delete',
    summary: 'Bulk-delete tax types',
    description: 'Soft-deletes up to 100 tax types at once. In-use rows report a 409 code per row.',
    tags: ['TaxTypes'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['ids'],
            properties: [new OA\Property(property: 'ids', type: 'array', maxItems: 100, items: new OA\Items(type: 'string', format: 'uuid'))]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Bulk-delete result'),
        new OA\Response(response: 422, description: 'Validation failed'),
    ]
)]
class TaxTypeController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly TaxTypeService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/tax-types',
        summary: 'List tax types',
        description: 'Returns a paginated list of tax types for the brand.',
        tags: ['TaxTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaxType')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TaxType::class);

        $taxTypes = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            // Brand scope comes from the {brandSlug} URL segment, resolved by
            // the ResolveBrandFromSlug middleware.
            'brand_id' => $request->attributes->get('brand_id'),
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return TaxTypeResource::collection($taxTypes);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/tax-types',
        summary: 'Create a tax type',
        description: 'Creates a new tax type for the brand. Setting is_default clears the previous brand default.',
        tags: ['TaxTypes'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'rate'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 50),
                    new OA\Property(property: 'name', type: 'string', maxLength: 100),
                    new OA\Property(property: 'rate', type: 'number', format: 'float'),
                    new OA\Property(property: 'is_default', type: 'boolean', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tax type created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaxType')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(TaxTypeStoreRequest $request): JsonResponse
    {
        $this->authorize('create', TaxType::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        // brand_id is required at the DB level but resolved from the
        // {brandSlug} URL segment by ResolveBrandFromSlug — never from the client.
        $data['brand_id'] = $request->attributes->get('brand_id');

        $taxType = $this->service->create($data);

        return (new TaxTypeResource($taxType))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/tax-types/{taxType}',
        summary: 'Get a tax type',
        description: 'Returns a single tax type by UUID.',
        tags: ['TaxTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'taxType', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tax type details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaxType')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(TaxType $taxType): TaxTypeResource
    {
        $this->authorizeOrganization($taxType);
        $this->authorize('view', $taxType);

        return new TaxTypeResource(
            $this->service->findById($taxType->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/tax-types/{taxType}',
        summary: 'Update a tax type',
        description: 'Updates an existing tax type. Fields are optional for partial updates. code is immutable; rate edits never touch historical orders.',
        tags: ['TaxTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'taxType', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'rate', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'is_default', type: 'boolean', nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated. On a rate change, meta.open_shift_branches lists the brand branches that are mid-shift right now (#1129) — advisory, the edit is NOT blocked.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/TaxType'),
                new OA\Property(property: 'meta', type: 'object', properties: [
                    new OA\Property(property: 'rate_changed', type: 'boolean'),
                    new OA\Property(property: 'open_shift_branches', type: 'array', items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'name', type: 'string', nullable: true),
                    ])),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(TaxTypeUpdateRequest $request, TaxType $taxType): JsonResponse
    {
        $this->authorizeOrganization($taxType);
        $this->authorize('update', $taxType);

        $data = $request->validated();

        // #1129 — a rate edit here lands on EVERY branch of the brand at once,
        // and it is deliberately NOT blocked (plan-043 Q6: per-line snapshots
        // protect orders already created). What was missing is the operator
        // half: a shift can end up spanning two rates with nothing said beyond
        // a static hint. Collect who is mid-shift BEFORE writing, so the
        // response names them and admin-web can warn with real data.
        //
        // Same predicate as the shop-level 409 guards (open/closing shift, or a
        // chain awaiting continuation after a handover), so a warning here can
        // never disagree with a block there.
        $rateChanged = array_key_exists('rate', $data)
            && (float) $data['rate'] !== (float) $taxType->rate;

        $openShiftBranches = $rateChanged && $taxType->brand_id !== null
            ? app(TillSessionService::class)->openShiftBranchesForBrand((string) $taxType->brand_id)
            : [];

        $taxType = $this->service->update($taxType, $data);

        return (new TaxTypeResource($taxType))
            ->additional([
                'meta' => [
                    // Always present on a rate change so the client can branch on
                    // the key rather than guessing from an absent field.
                    'rate_changed' => $rateChanged,
                    'open_shift_branches' => $openShiftBranches,
                ],
            ])
            ->response();
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/tax-types/{taxType}',
        summary: 'Delete a tax type',
        description: 'Soft-deletes a tax type. Returns 409 TAX_TYPE_IN_USE when referenced by a product, menu override, or branch default.',
        tags: ['TaxTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'taxType', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'Tax type in use'),
        ]
    )]
    public function destroy(TaxType $taxType): JsonResponse
    {
        $this->authorizeOrganization($taxType);
        $this->authorize('delete', $taxType);

        $this->service->delete($taxType);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/tax-types/{taxType}/restore',
        summary: 'Restore a soft-deleted tax type',
        description: 'Restores a previously soft-deleted tax type.',
        tags: ['TaxTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'taxType', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaxType')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function restore(Request $request, string $id): TaxTypeResource
    {
        $taxType = TaxType::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            // Scope by brand so an HQ user of brand A cannot restore brand B's
            // soft-deleted tax types by guessing their UUID.
            ->where('brand_id', $request->attributes->get('brand_id'))
            ->findOrFail($id);

        $this->authorize('restore', $taxType);

        $taxType = $this->service->restore($taxType);

        return new TaxTypeResource($taxType);
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/tax-types/lookup',
        summary: 'Lookup tax types',
        description: 'Returns a lightweight list of active tax types (id, code, name, rates, is_default) for select/combobox.',
        tags: ['TaxTypes'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        ]
    )]
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TaxType::class);

        return response()->json([
            'data' => $this->service->lookup(
                $this->getOrganizationId(),
                $request->attributes->get('brand_id'),
            ),
        ]);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/tax-types/{taxType}/toggle-status',
        summary: 'Toggle tax type active status',
        description: 'Toggles the is_active flag. Deactivation blocks new assignment but keeps existing references valid.',
        tags: ['TaxTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'taxType', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Toggled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaxType')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function toggleStatus(TaxType $taxType): TaxTypeResource
    {
        $this->authorizeOrganization($taxType);
        $this->authorize('update', $taxType);

        $taxType = $this->service->toggleStatus($taxType);

        return new TaxTypeResource($taxType);
    }

    // =========================================================================
    //  Bulk Operations
    // =========================================================================

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $orgId = $this->getOrganizationId();

        // Scope the id set to the current org + brand so a caller cannot delete
        // another brand's types by id. Unknown/foreign ids fall through to the
        // service's "Not found" per-row error.
        $ids = TaxType::query()
            ->where('organization_id', $orgId)
            ->where('brand_id', $request->attributes->get('brand_id'))
            ->whereIn('id', $request->input('ids'))
            ->pluck('id')
            ->all();

        $result = $this->service->bulkDelete($ids);

        return response()->json([
            'message' => "{$result['deleted']} items deleted.",
            'deleted' => $result['deleted'],
            'errors' => $result['errors'],
        ]);
    }
}
