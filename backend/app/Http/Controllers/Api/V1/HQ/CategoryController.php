<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasBulkOperations;
use App\Http\Controllers\Traits\HasImportExport;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\CategoryApplyTaxTypeRequest;
use App\Http\Requests\CategoryStoreRequest;
use App\Http\Requests\CategoryUpdateRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\Export\CategoryExporter;
use App\Services\Export\CsvExporter;
use App\Services\Import\CategoryImporter;
use App\Services\Import\CsvImporter;
use App\Services\Product\Commands\AssignCategoryTaxTypeCommand;
use App\Services\Product\Commands\CategoryLifecycleCommand;
use App\Services\Product\Commands\CreateCategoryCommand;
use App\Services\Product\Commands\ReviseCategoryCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\Enums\CategoryLifecycleAction;
use App\Services\Product\ProductQueryService;
use App\Services\Product\ValueObjects\CategoryPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/categories/import',
    summary: 'Import categories from CSV',
    description: 'Bulk-imports categories from a CSV file.',
    tags: ['Categories'],
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
    path: '/api/v1/hq/{brandSlug}/categories/export',
    summary: 'Export categories to CSV',
    description: 'Streams a CSV download of categories.',
    tags: ['Categories'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
    ]
)]
#[OA\Get(
    path: '/api/v1/hq/{brandSlug}/categories/import/template',
    summary: 'Download category import template',
    description: 'Returns an empty CSV template for category import.',
    tags: ['Categories'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'CSV template', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
    ]
)]
#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/categories/bulk-delete',
    summary: 'Bulk-delete categories',
    description: 'Soft-deletes up to 100 categories at once.',
    tags: ['Categories'],
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
class CategoryController extends Controller
{
    use AuthorizesRequests;
    use HasBulkOperations;
    use HasImportExport;
    use HasOrganizationContext;

    public function __construct(
        private readonly ProductMutationFacade $mutations,
        private readonly ProductQueryService $queries,
        private readonly CategoryImporter $importer,
        private readonly CategoryExporter $exporter,
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
        return 'categories_import_template';
    }

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/categories',
        summary: 'List categories',
        description: 'Returns a paginated list of categories. Supports tree filtering by parent_id.',
        tags: ['Categories'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'parent_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Category')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Category::class);

        $categories = $this->queries->categories([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'parent_id' => $request->input('parent_id'),
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return CategoryResource::collection($categories);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/categories',
        summary: 'Create a category',
        description: 'Creates a new category. Supports tree structure via parent_id.',
        tags: ['Categories'],
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
                    new OA\Property(property: 'parent_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Category created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Category')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(CategoryStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $categoryId = (string) Str::uuid();
        $payload = $this->payload($request->validated());
        $this->mutations->createCategory(new CreateCategoryCommand(
            $this->context($request, "category:create:{$categoryId}"),
            $categoryId,
            $request->attributes->get('brand_id'),
            $payload,
            $payload->fingerprint(),
        ));
        $category = $this->queries->category($this->getOrganizationId(), $request->attributes->get('brand_id'), $categoryId);
        foreach (SupportedLocale::cases() as $locale) {
            if (trim((string) $request->input("{$locale->value}.name", '')) !== '') {
                $category->setDefaultLocale($locale->value);
                break;
            }
        }

        return (new CategoryResource($category))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/categories/{category}',
        summary: 'Get a category',
        description: 'Returns a single category by UUID.',
        tags: ['Categories'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Category')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request): CategoryResource
    {
        $category = $this->resolveCategory($request);
        $this->authorizeOrganization($category);
        $this->authorizeBrand($category);
        $this->authorize('view', $category);

        return new CategoryResource(
            $this->queries->category($this->getOrganizationId(), $request->attributes->get('brand_id'), $category->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/categories/{category}',
        summary: 'Update a category',
        description: 'Updates a category. Fields are nullable for partial updates.',
        tags: ['Categories'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'sku', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'parent_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Category')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(CategoryUpdateRequest $request): CategoryResource
    {
        $category = $this->resolveCategory($request);
        $this->authorizeOrganization($category);
        $this->authorizeBrand($category);
        $this->authorize('update', $category);

        $payload = $this->payload($request->validated(), $category);
        $this->mutations->reviseCategory(new ReviseCategoryCommand(
            $this->context($request, "category:revise:{$category->id}", $category),
            $category->id,
            $category->brand_id,
            $payload,
            $payload->fingerprint(),
        ));
        $category = $this->queries->category($this->getOrganizationId(), $request->attributes->get('brand_id'), $category->id);

        return new CategoryResource($category);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/categories/{category}',
        summary: 'Delete a category',
        description: 'Soft-deletes a category.',
        tags: ['Categories'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $category = $this->resolveCategory($request);
        $this->authorizeOrganization($category);
        $this->authorizeBrand($category);
        $this->authorize('delete', $category);

        $this->mutations->archiveCategory($this->lifecycle($request, $category, CategoryLifecycleAction::Archive));

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/categories/{category}/restore',
        summary: 'Restore a soft-deleted category',
        description: 'Restores a previously soft-deleted category.',
        tags: ['Categories'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Category')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function restore(Request $request): CategoryResource
    {
        $id = $request->route('category');

        $category = Category::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            ->where('brand_id', $request->attributes->get('brand_id'))
            ->findOrFail($id);

        $this->authorize('restore', $category);

        $this->mutations->restoreCategory($this->lifecycle($request, $category, CategoryLifecycleAction::Restore));
        $category->refresh();

        return new CategoryResource($category);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/categories/{category}/apply-tax-type',
        summary: 'Bulk-assign a tax type to every product in a category',
        description: 'Sets products.tax_type_id for all products in the category. Pass tax_type_id: null to clear the override so products inherit the branch/brand default.',
        tags: ['Categories'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'tax_type_id', type: 'string', format: 'uuid', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Assigned', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'category_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'tax_type_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'updated', type: 'integer'),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function applyTaxType(CategoryApplyTaxTypeRequest $request): JsonResponse
    {
        $category = $this->resolveCategory($request);
        $this->authorizeOrganization($category);
        $this->authorizeBrand($category);
        $this->authorize('update', $category);

        $result = $this->mutations->assignCategoryTaxType(new AssignCategoryTaxTypeCommand(
            $this->context($request, "category:apply-tax-type:{$category->id}", $category),
            $category->id,
            $category->brand_id,
            $request->validated('tax_type_id'),
        ));

        return response()->json(['data' => [
            'category_id' => $result->categoryId,
            'tax_type_id' => $result->taxTypeId,
            'updated' => $result->updated,
        ]]);
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/categories/lookup',
        summary: 'Lookup categories',
        description: 'Returns a lightweight list of categories for select/combobox.',
        tags: ['Categories'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        ]
    )]
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        return response()->json([
            'data' => $this->queries->categoryLookup($this->getOrganizationId(), $request->attributes->get('brand_id')),
        ]);
    }

    // =========================================================================
    //  Bulk Operations
    // =========================================================================

    protected function getModelClass(): string
    {
        return Category::class;
    }

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
            $category = Category::where('organization_id', $orgId)
                ->where('brand_id', $request->attributes->get('brand_id'))
                ->find($id);

            if (! $category) {
                $errors[] = ['id' => $id, 'message' => 'Not found'];

                continue;
            }

            try {
                $this->authorize('delete', $category);
                $this->mutations->archiveCategory($this->lifecycle($request, $category, CategoryLifecycleAction::Archive));
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $id,
                    'name' => $category->name ?? null,
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

    /**
     * Resolve the {category} route parameter to a Category model.
     *
     * Mirrors the pattern in ProductController — implicit route binding fails
     * for nested route groups in this app, so the route value is read manually
     * and resolved with findOrFail (404 on missing).
     */
    private function resolveCategory(Request $request): Category
    {
        $resolved = $request->route('category');

        return $resolved instanceof Category ? $resolved : Category::findOrFail($resolved);
    }

    /** @param array<string, mixed> $data */
    private function payload(array $data, ?Category $current = null): CategoryPayload
    {
        $translations = [];
        $clearedLocales = [];
        foreach (SupportedLocale::cases() as $locale) {
            $localized = $data[$locale->value] ?? null;
            if (is_array($localized) && trim((string) ($localized['name'] ?? '')) !== '') {
                $translations[] = new LocalizedText($locale, $localized['name'], $localized['description'] ?? null);
            } elseif (array_key_exists($locale->value, $data)) {
                $clearedLocales[] = $locale->value;
            }
        }
        $localizedName = null;
        foreach (SupportedLocale::cases() as $locale) {
            $candidate = $data[$locale->value]['name'] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                $localizedName = $candidate;
                break;
            }
        }

        return new CategoryPayload(
            (string) ($data['name'] ?? $localizedName ?? $current?->name),
            array_key_exists('sku', $data) ? $data['sku'] : $current?->sku,
            array_key_exists('slug', $data) ? $data['slug'] : $current?->slug,
            array_key_exists('description', $data) ? $data['description'] : $current?->description,
            array_key_exists('parent_id', $data) ? $data['parent_id'] : $current?->parent_id,
            (bool) ($data['is_active'] ?? $current?->is_active ?? true),
            (bool) ($data['is_featured'] ?? $current?->is_featured ?? false),
            $translations,
            array_key_exists('image_url', $data) ? $data['image_url'] : $current?->image_url,
            $clearedLocales,
        );
    }

    private function lifecycle(Request $request, Category $category, CategoryLifecycleAction $action): CategoryLifecycleCommand
    {
        return new CategoryLifecycleCommand(
            $this->context($request, "category:{$action->value}:{$category->id}", $category),
            $category->id,
            $category->brand_id,
            $action,
        );
    }

    private function context(Request $request, string $fallbackKey, ?Category $current = null): MutationContext
    {
        return new MutationContext(
            $this->getOrganizationId(),
            $request->user()?->id,
            $request->header('X-Correlation-ID') ?: (string) Str::uuid(),
            $request->header('Idempotency-Key') ?: $fallbackKey,
        );
    }
}
