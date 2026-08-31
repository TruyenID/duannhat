<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Exceptions\CircularReferenceException;
use App\Exceptions\ComponentInUseException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasBulkOperations;
use App\Http\Controllers\Traits\HasImportExport;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\MaterialStoreRequest;
use App\Http\Requests\MaterialUpdateRequest;
use App\Http\Resources\MaterialResource;
use App\Models\Material;
use App\Services\Export\CsvExporter;
use App\Services\Export\MaterialExporter;
use App\Services\Import\CsvImporter;
use App\Services\Import\MaterialImporter;
use App\Services\Product\MaterialService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/materials/import',
    summary: 'Import materials from CSV',
    description: 'Bulk-imports materials from a CSV file.',
    tags: ['Materials'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file', 'brand_id'],
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary'),
                    new OA\Property(property: 'brand_id', type: 'string', format: 'uuid'),
                ]
            )
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Import succeeded'),
        new OA\Response(response: 422, description: 'Validation failed'),
    ]
)]
#[OA\Get(
    path: '/api/v1/hq/{brandSlug}/materials/export',
    summary: 'Export materials to CSV',
    description: 'Streams a CSV download of materials.',
    tags: ['Materials'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
    ]
)]
#[OA\Get(
    path: '/api/v1/hq/{brandSlug}/materials/import/template',
    summary: 'Download material import template',
    description: 'Returns an empty CSV template for material import.',
    tags: ['Materials'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'CSV template', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
    ]
)]
#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/materials/bulk-delete',
    summary: 'Bulk-delete materials',
    description: 'Soft-deletes up to 100 materials at once.',
    tags: ['Materials'],
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
class MaterialController extends Controller
{
    use AuthorizesRequests;
    use HasBulkOperations;
    use HasImportExport;
    use HasOrganizationContext;

    public function __construct(
        private readonly MaterialService $service,
        private readonly MaterialImporter $importer,
        private readonly MaterialExporter $exporter,
    ) {}

    protected function getImporter(): CsvImporter
    {
        return $this->importer;
    }

    protected function getExporter(): CsvExporter
    {
        return $this->exporter;
    }

    protected function getTemplateFilename(): string
    {
        return 'materials_import_template';
    }

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/materials',
        summary: 'List materials',
        description: 'Returns a paginated list of materials.',
        tags: ['Materials'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'kind', in: 'query', required: false, description: 'Filter by material kind: raw (no yield_unit, no BOM) or produced (has yield_unit + active recipe).', schema: new OA\Schema(type: 'string', enum: ['raw', 'produced'])),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Material')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Material::class);

        $materials = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'search' => $request->input('search'),
            'kind' => $request->input('kind'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return MaterialResource::collection($materials);
    }

    /**
     * @throws CircularReferenceException
     */
    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/materials',
        summary: 'Create a material',
        description: 'Creates a new material.',
        tags: ['Materials'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'sku', type: 'string', maxLength: 50, nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Material created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Material')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(MaterialStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Material::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();

        $material = $this->service->create($data);

        return (new MaterialResource($material))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/materials/{material}',
        summary: 'Get a material',
        description: 'Returns a single material by UUID.',
        tags: ['Materials'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Material', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Material')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Material $material): MaterialResource
    {
        $this->authorizeOrganization($material);
        $this->authorizeBrand($material);
        $this->authorize('view', $material);

        return new MaterialResource(
            $this->service->findById($material->id)
        );
    }

    /**
     * @throws CircularReferenceException
     */
    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/materials/{material}',
        summary: 'Update a material',
        description: 'Updates a material. Fields are nullable for partial updates.',
        tags: ['Materials'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'sku', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Material')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(MaterialUpdateRequest $request, Material $material): MaterialResource
    {
        $this->authorizeOrganization($material);
        $this->authorizeBrand($material);
        $this->authorize('update', $material);

        $material = $this->service->update($material, $request->validated());

        return new MaterialResource($material);
    }

    /**
     * @throws ComponentInUseException
     */
    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/materials/{material}',
        summary: 'Delete a material',
        description: 'Soft-deletes a material. Fails if it is in use by recipes.',
        tags: ['Materials'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Material is in use'),
        ]
    )]
    public function destroy(Material $material): JsonResponse
    {
        $this->authorizeOrganization($material);
        $this->authorizeBrand($material);
        $this->authorize('delete', $material);

        $this->service->delete($material);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/materials/{material}/restore',
        summary: 'Restore a trashed material',
        description: 'Restores a soft-deleted material.',
        tags: ['Materials'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Material')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function restore(string $id): MaterialResource
    {
        $material = Material::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->findOrFail($id);

        $this->authorize('restore', $material);

        $material = $this->service->restore($material);

        return new MaterialResource($material);
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/materials/lookup',
        summary: 'Lookup materials',
        description: 'Returns a lightweight list of materials for select/combobox.',
        tags: ['Materials'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        ]
    )]
    public function lookup(): JsonResponse
    {
        $this->authorize('viewAny', Material::class);

        return response()->json([
            'data' => $this->service->lookup(
                $this->getOrganizationId(),
                includeIds: [],
                brandId: request()->attributes->get('brand_id'),
            ),
        ]);
    }

    // =========================================================================
    //  Custom
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/materials/{material}/check-usage',
        summary: 'Check usage of a material',
        description: 'Returns information about where the material is used (recipes, products).',
        tags: ['Materials'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Usage report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function checkUsage(Material $material): JsonResponse
    {
        $this->authorizeOrganization($material);
        $this->authorizeBrand($material);
        $this->authorize('view', $material);

        return response()->json([
            'data' => $this->service->checkUsage($material),
        ]);
    }

    // =========================================================================
    //  Bulk Operations
    // =========================================================================

    protected function getModelClass(): string
    {
        return Material::class;
    }
}
