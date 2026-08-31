<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\AllergenStoreRequest;
use App\Http\Requests\AllergenUpdateRequest;
use App\Http\Resources\AllergenResource;
use App\Models\Allergen;
use App\Services\Omnify\AllergenService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class AllergenController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly AllergenService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/allergens',
        summary: 'List allergens',
        description: 'Returns a paginated list of allergens. Filterable by jurisdiction / severity / is_active.',
        tags: ['Allergens'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'jurisdiction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['jp', 'eu', 'us'])),
            new OA\Parameter(name: 'severity', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['mandatory', 'recommended'])),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Allergen')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Allergen::class);

        $allergens = $this->service->list([
            'search' => $request->input('search'),
            'jurisdiction' => $request->input('jurisdiction'),
            'severity' => $request->input('severity'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', 'code'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return AllergenResource::collection($allergens);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/allergens',
        summary: 'Create an allergen',
        description: 'Creates a new allergen. Translatable name accepts per-locale flat keys (`name:ja`, `name:en`, `name:vi`).',
        tags: ['Allergens'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'jurisdiction'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 60),
                    new OA\Property(property: 'name', type: 'string', maxLength: 120, nullable: true),
                    new OA\Property(property: 'name:ja', type: 'string', maxLength: 120, nullable: true),
                    new OA\Property(property: 'name:en', type: 'string', maxLength: 120, nullable: true),
                    new OA\Property(property: 'name:vi', type: 'string', maxLength: 120, nullable: true),
                    new OA\Property(property: 'jurisdiction', type: 'string', enum: ['jp', 'eu', 'us']),
                    new OA\Property(property: 'severity', type: 'string', enum: ['mandatory', 'recommended'], default: 'mandatory'),
                    new OA\Property(property: 'is_active', type: 'boolean', default: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Allergen')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(AllergenStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Allergen::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();

        $allergen = $this->service->create($data);

        return (new AllergenResource($allergen))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/allergens/{allergen}',
        summary: 'Get an allergen',
        tags: ['Allergens'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'allergen', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Allergen', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Allergen')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request): AllergenResource
    {
        $allergen = $this->resolveAllergen($request);
        $this->authorizeOrganization($allergen);
        $this->authorize('view', $allergen);

        return new AllergenResource($this->service->findById($allergen->id));
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/allergens/{allergen}',
        summary: 'Update an allergen',
        tags: ['Allergens'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'allergen', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 60, nullable: true),
                new OA\Property(property: 'name', type: 'string', maxLength: 120, nullable: true),
                new OA\Property(property: 'name:ja', type: 'string', maxLength: 120, nullable: true),
                new OA\Property(property: 'name:en', type: 'string', maxLength: 120, nullable: true),
                new OA\Property(property: 'name:vi', type: 'string', maxLength: 120, nullable: true),
                new OA\Property(property: 'jurisdiction', type: 'string', enum: ['jp', 'eu', 'us'], nullable: true),
                new OA\Property(property: 'severity', type: 'string', enum: ['mandatory', 'recommended'], nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Allergen')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(AllergenUpdateRequest $request): AllergenResource
    {
        $allergen = $this->resolveAllergen($request);
        $this->authorizeOrganization($allergen);
        $this->authorize('update', $allergen);

        $allergen = $this->service->update($allergen, $request->validated());

        return new AllergenResource($allergen);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/allergens/{allergen}',
        summary: 'Delete an allergen',
        description: 'Soft-deletes an allergen. Returns 409 ALLERGEN_IN_USE if any Material still references it.',
        tags: ['Allergens'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'allergen', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'In use', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'error', type: 'string', example: 'ALLERGEN_IN_USE'),
                new OA\Property(property: 'used_by', type: 'array', items: new OA\Items(type: 'object')),
            ])),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $allergen = $this->resolveAllergen($request);
        $this->authorizeOrganization($allergen);
        $this->authorize('delete', $allergen);

        $this->service->delete($allergen);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/allergens/{allergen}/restore',
        summary: 'Restore a soft-deleted allergen',
        tags: ['Allergens'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'allergen', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Allergen')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function restore(Request $request): AllergenResource
    {
        $id = $request->route('allergen');

        $allergen = Allergen::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->findOrFail($id);

        $this->authorize('restore', $allergen);

        $allergen = $this->service->restore($allergen);

        return new AllergenResource($allergen);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/allergens/bulk-delete',
        summary: 'Bulk delete allergens',
        description: 'Soft-deletes up to 100 allergens. Skips any that are in use or unauthorized.',
        tags: ['Allergens'],
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
            new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'deleted', type: 'integer'),
                new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'object')),
            ])),
        ]
    )]
    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $orgId = $this->getOrganizationId();
        $deleted = 0;
        $errors = [];

        foreach ($request->input('ids') as $id) {
            $allergen = Allergen::where('organization_id', $orgId)->find($id);

            if (! $allergen) {
                $errors[] = ['id' => $id, 'message' => 'Not found'];

                continue;
            }

            try {
                $this->authorize('delete', $allergen);
                $this->service->delete($allergen);
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $id,
                    'name' => $allergen->name ?? null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => "{$deleted} items deleted.",
            'deleted' => $deleted,
            'errors' => $errors,
        ]);
    }

    private function resolveAllergen(Request $request): Allergen
    {
        $resolved = $request->route('allergen');

        return $resolved instanceof Allergen ? $resolved : Allergen::findOrFail($resolved);
    }
}
