<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasBulkOperations;
use App\Http\Controllers\Traits\HasImportExport;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\RecipeStoreRequest;
use App\Http\Requests\RecipeUpdateRequest;
use App\Http\Requests\RejectRecipeRequest;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use App\Services\Export\CsvExporter;
use App\Services\Export\RecipeExporter;
use App\Services\Import\CsvImporter;
use App\Services\Import\RecipeImporter;
use App\Services\Product\RecipeService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/recipes/import',
    summary: 'Import recipes from CSV',
    description: 'Bulk-imports recipes from a CSV file.',
    tags: ['Recipes'],
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
    path: '/api/v1/hq/{brandSlug}/recipes/export',
    summary: 'Export recipes to CSV',
    description: 'Streams a CSV download of recipes.',
    tags: ['Recipes'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
    ]
)]
#[OA\Get(
    path: '/api/v1/hq/{brandSlug}/recipes/import/template',
    summary: 'Download recipe import template',
    description: 'Returns an empty CSV template for recipe import.',
    tags: ['Recipes'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'CSV template', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
    ]
)]
#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/recipes/bulk-delete',
    summary: 'Bulk-delete recipes',
    description: 'Soft-deletes up to 100 recipes at once.',
    tags: ['Recipes'],
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
class RecipeController extends Controller
{
    use AuthorizesRequests;
    use HasBulkOperations;
    use HasImportExport;
    use HasOrganizationContext;

    public function __construct(
        private readonly RecipeService $service,
        private readonly RecipeImporter $importer,
        private readonly RecipeExporter $exporter,
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
        return 'recipes_import_template';
    }

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/recipes',
        summary: 'List recipes',
        description: 'Returns a paginated list of recipes.',
        tags: ['Recipes'],
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
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Recipe')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Recipe::class);

        $recipes = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'material_id' => $request->input('material_id'),
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return RecipeResource::collection($recipes);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/recipes',
        summary: 'Create a recipe',
        description: 'Creates a new recipe.',
        tags: ['Recipes'],
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
            new OA\Response(response: 201, description: 'Recipe created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Recipe')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(RecipeStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Recipe::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();

        $recipe = $this->service->create($data);

        return (new RecipeResource($recipe))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/recipes/{recipe}',
        summary: 'Get a recipe',
        description: 'Returns a single recipe by UUID.',
        tags: ['Recipes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recipe', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Recipe', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Recipe')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Recipe $recipe): RecipeResource
    {
        $this->authorizeOrganization($recipe);
        $this->authorizeBrand($recipe);
        $this->authorize('view', $recipe);

        return new RecipeResource(
            $this->service->findById($recipe->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/recipes/{recipe}',
        summary: 'Update a recipe',
        description: 'Updates a recipe. Fields are nullable for partial updates.',
        tags: ['Recipes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recipe', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
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
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Recipe')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(RecipeUpdateRequest $request, Recipe $recipe): RecipeResource
    {
        $this->authorizeOrganization($recipe);
        $this->authorizeBrand($recipe);
        $this->authorize('update', $recipe);

        $recipe = $this->service->update($recipe, $request->validated());

        return new RecipeResource($recipe);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/recipes/{recipe}',
        summary: 'Delete a recipe',
        description: 'Soft-deletes a recipe.',
        tags: ['Recipes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recipe', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Recipe $recipe): JsonResponse
    {
        $this->authorizeOrganization($recipe);
        $this->authorizeBrand($recipe);
        $this->authorize('delete', $recipe);

        $this->service->delete($recipe);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/recipes/{recipe}/restore',
        summary: 'Restore a soft-deleted recipe',
        description: 'Restores a previously soft-deleted recipe.',
        tags: ['Recipes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recipe', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Recipe')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function restore(string $id): RecipeResource
    {
        $recipe = Recipe::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->findOrFail($id);

        $this->authorize('restore', $recipe);

        $recipe = $this->service->restore($recipe);

        return new RecipeResource($recipe);
    }

    // =========================================================================
    //  Workflow Actions (HasApprovalWorkflow trait — plan-003)
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/recipes/{recipe}/submit-for-approval',
        summary: 'Submit a recipe for approval',
        description: 'Transitions draft|rejected → pending. Logs recipe.submitted_for_approval audit row.',
        tags: ['Recipes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recipe', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Recipe')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Recipe not found'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ]
    )]
    public function submitForApproval(Recipe $recipe): RecipeResource
    {
        $this->authorizeOrganization($recipe);
        $this->authorizeBrand($recipe);
        $this->authorize('submitForApproval', $recipe);

        return new RecipeResource(
            $this->service->submitForApproval($recipe)
        );
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/recipes/{recipe}/approve',
        summary: 'Approve a recipe',
        description: 'Transitions pending → approved. Returns 422 cannot_approve_own_submission if approver matches creator.',
        tags: ['Recipes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recipe', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Recipe')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Recipe not found'),
            new OA\Response(response: 422, description: 'Invalid status transition or cannot approve own'),
        ]
    )]
    public function approve(Request $request, Recipe $recipe): RecipeResource
    {
        $this->authorizeOrganization($recipe);
        $this->authorizeBrand($recipe);
        $this->authorize('approve', $recipe);

        return new RecipeResource(
            $this->service->approve($recipe, $request->user())
        );
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/recipes/{recipe}/reject',
        summary: 'Reject a recipe',
        description: 'Transitions pending → rejected. Requires non-empty rejection_reason (max 1000).',
        tags: ['Recipes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recipe', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['rejection_reason'],
                properties: [
                    new OA\Property(property: 'rejection_reason', type: 'string', maxLength: 1000),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Recipe')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Recipe not found'),
            new OA\Response(response: 422, description: 'Validation failed or invalid state'),
        ]
    )]
    public function reject(RejectRecipeRequest $request, Recipe $recipe): RecipeResource
    {
        $this->authorizeOrganization($recipe);
        $this->authorizeBrand($recipe);
        $this->authorize('reject', $recipe);

        return new RecipeResource(
            $this->service->reject($recipe, $request->user(), $request->validated('rejection_reason'))
        );
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/recipes/lookup',
        summary: 'Lookup recipes',
        description: 'Returns a lightweight list of recipes for select/combobox.',
        tags: ['Recipes'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        ]
    )]
    public function lookup(): JsonResponse
    {
        $this->authorize('viewAny', Recipe::class);

        return response()->json([
            'data' => $this->service->lookup(
                $this->getOrganizationId(),
                request()->attributes->get('brand_id'),
            ),
        ]);
    }

    // =========================================================================
    //  Bulk Operations
    // =========================================================================

    protected function getModelClass(): string
    {
        return Recipe::class;
    }
}
