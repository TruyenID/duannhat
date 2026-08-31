<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasBulkOperations;
use App\Http\Controllers\Traits\HasImportExport;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ProductTypeStoreRequest;
use App\Http\Requests\ProductTypeUpdateRequest;
use App\Http\Resources\ProductTypeResource;
use App\Models\ProductType;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\Export\CsvExporter;
use App\Services\Export\ProductTypeExporter;
use App\Services\Import\CsvImporter;
use App\Services\Import\ProductTypeImporter;
use App\Services\Product\Commands\CreateProductTypeCommand;
use App\Services\Product\Commands\ProductTypeLifecycleCommand;
use App\Services\Product\Commands\ReviseProductTypeCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\Enums\ProductTypeLifecycleAction;
use App\Services\Product\ProductQueryService;
use App\Services\Product\ValueObjects\ProductTypePayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/product-types/import',
    summary: 'Import product types from CSV',
    description: 'Bulk-imports product types from a CSV file.',
    tags: ['ProductTypes'],
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
        new OA\Response(response: 401, description: 'Unauthenticated'),
        new OA\Response(response: 422, description: 'Validation failed'),
    ]
)]
#[OA\Get(
    path: '/api/v1/hq/{brandSlug}/product-types/export',
    summary: 'Export product types to CSV',
    description: 'Streams a CSV download of product types.',
    tags: ['ProductTypes'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(response: 401, description: 'Unauthenticated'),
    ]
)]
#[OA\Get(
    path: '/api/v1/hq/{brandSlug}/product-types/import/template',
    summary: 'Download product type import template',
    description: 'Returns an empty CSV template.',
    tags: ['ProductTypes'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'CSV template', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
    ]
)]
#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/product-types/bulk-delete',
    summary: 'Bulk-delete product types',
    description: 'Soft-deletes up to 100 product types at once.',
    tags: ['ProductTypes'],
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
class ProductTypeController extends Controller
{
    use AuthorizesRequests;
    use HasBulkOperations;
    use HasImportExport;
    use HasOrganizationContext;

    public function __construct(
        private readonly ProductMutationFacade $mutations,
        private readonly ProductQueryService $queries,
        private readonly ProductTypeImporter $importer,
        private readonly ProductTypeExporter $exporter,
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
        return 'product_types_import_template';
    }

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/product-types',
        summary: 'List product types',
        description: 'Returns a paginated list of product types for the brand.',
        tags: ['ProductTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'product_form', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['physical', 'digital'])),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductType')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProductType::class);

        $productTypes = $this->queries->productTypes([
            'organization_id' => $this->getOrganizationId(),
            // Brand scope comes from the {brandSlug} URL segment, resolved by
            // the ResolveBrandFromSlug middleware. Without this filter, HQ
            // pages of one brand would see product types of every brand under
            // the same organization.
            'brand_id' => $request->attributes->get('brand_id'),
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'product_form' => $request->input('product_form'),
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return ProductTypeResource::collection($productTypes);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/product-types',
        summary: 'Create a product type',
        description: 'Creates a new product type for the brand.',
        tags: ['ProductTypes'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'product_form', type: 'string', enum: ['physical', 'digital'], nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Product type created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductType')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(ProductTypeStoreRequest $request): JsonResponse
    {
        $this->authorize('create', ProductType::class);

        $productTypeId = (string) Str::uuid();
        $payload = $this->payload($request->validated());
        $brandId = $request->attributes->get('brand_id');
        $this->mutations->createProductType(new CreateProductTypeCommand($this->context($request, "product-type:create:{$productTypeId}"), $productTypeId, $brandId, $payload, $payload->fingerprint()));
        $productType = $this->queries->productType($this->getOrganizationId(), $brandId, $productTypeId);

        return (new ProductTypeResource($productType))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/product-types/{productType}',
        summary: 'Get a product type',
        description: 'Returns a single product type by UUID.',
        tags: ['ProductTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productType', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product type details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductType')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, ProductType $productType): ProductTypeResource
    {
        $this->authorizeOrganization($productType);
        $this->authorizeBrand($productType);
        $this->authorize('view', $productType);

        return new ProductTypeResource(
            $this->queries->productType($this->getOrganizationId(), $request->attributes->get('brand_id'), $productType->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/product-types/{productType}',
        summary: 'Update a product type',
        description: 'Updates an existing product type. Fields are nullable for partial updates.',
        tags: ['ProductTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productType', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'product_form', type: 'string', enum: ['physical', 'digital'], nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductType')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(ProductTypeUpdateRequest $request, ProductType $productType): ProductTypeResource
    {
        $this->authorizeOrganization($productType);
        $this->authorizeBrand($productType);
        $this->authorize('update', $productType);

        $payload = $this->payload($request->validated(), $productType);
        $this->mutations->reviseProductType(new ReviseProductTypeCommand($this->context($request, "product-type:revise:{$productType->id}", $productType), $productType->id, $productType->brand_id, $payload, $payload->fingerprint()));
        $productType->refresh()->load('translations')->loadCount('products');

        return new ProductTypeResource($productType);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/product-types/{productType}',
        summary: 'Delete a product type',
        description: 'Soft-deletes a product type.',
        tags: ['ProductTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productType', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, ProductType $productType): JsonResponse
    {
        $this->authorizeOrganization($productType);
        $this->authorizeBrand($productType);
        $this->authorize('delete', $productType);

        $this->mutations->archiveProductType($this->lifecycle($request, $productType, ProductTypeLifecycleAction::Archive));

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/product-types/{productType}/restore',
        summary: 'Restore a soft-deleted product type',
        description: 'Restores a previously soft-deleted product type.',
        tags: ['ProductTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productType', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductType')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function restore(Request $request, string $id): ProductTypeResource
    {
        $productType = ProductType::withTrashed()
            ->where('organization_id', $this->getOrganizationId())
            // Scope by brand so an HQ user of brand A cannot restore brand B's
            // soft-deleted product types by guessing their UUID.
            ->where('brand_id', $request->attributes->get('brand_id'))
            ->findOrFail($id);

        $this->authorize('restore', $productType);

        $this->mutations->restoreProductType($this->lifecycle($request, $productType, ProductTypeLifecycleAction::Restore));
        $productType->refresh()->load('translations')->loadCount('products');

        return new ProductTypeResource($productType);
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/product-types/lookup',
        summary: 'Lookup product types',
        description: 'Returns a lightweight list of product types for select/combobox.',
        tags: ['ProductTypes'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        ]
    )]
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductType::class);

        return response()->json([
            'data' => $this->queries->productTypeLookup(
                $this->getOrganizationId(),
                $request->attributes->get('brand_id'),
            ),
        ]);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/product-types/{productType}/toggle-status',
        summary: 'Toggle product type active status',
        description: 'Toggles the is_active flag.',
        tags: ['ProductTypes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'productType', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Toggled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductType')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function toggleStatus(Request $request, ProductType $productType): ProductTypeResource
    {
        $this->authorizeOrganization($productType);
        $this->authorizeBrand($productType);
        $this->authorize('update', $productType);

        $this->mutations->toggleProductTypeStatus($this->lifecycle($request, $productType, ProductTypeLifecycleAction::ToggleStatus));
        $productType->refresh()->load('translations')->loadCount('products');

        return new ProductTypeResource($productType);
    }

    // =========================================================================
    //  Bulk Operations
    // =========================================================================

    protected function getModelClass(): string
    {
        return ProductType::class;
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
            $model = ProductType::where('organization_id', $orgId)->where('brand_id', $request->attributes->get('brand_id'))->find($id);

            if (! $model) {
                $errors[] = ['id' => $id, 'message' => 'Not found'];

                continue;
            }

            try {
                $this->authorize('delete', $model);
                $this->mutations->archiveProductType($this->lifecycle($request, $model, ProductTypeLifecycleAction::Archive));
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = ['id' => $id, 'name' => $model->name ?? null, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => "{$deleted} items deleted.",
            'deleted' => $deleted,
            'errors' => $errors,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function payload(array $data, ?ProductType $current = null): ProductTypePayload
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

        return new ProductTypePayload(
            (string) ($data['name'] ?? $localizedName ?? $current?->name),
            array_key_exists('code', $data) ? $data['code'] : $current?->code,
            array_key_exists('description', $data) ? $data['description'] : $current?->description,
            (string) ($data['product_form'] ?? $current?->product_form ?? 'physical'),
            (bool) ($data['has_recipe'] ?? $current?->has_recipe ?? true),
            (bool) ($data['is_inventory_tracked'] ?? $current?->is_inventory_tracked ?? true),
            array_key_exists('icon', $data) ? $data['icon'] : $current?->icon,
            (bool) ($data['is_active'] ?? $current?->is_active ?? true),
            $translations,
            $clearedLocales,
        );
    }

    private function lifecycle(Request $request, ProductType $productType, ProductTypeLifecycleAction $action): ProductTypeLifecycleCommand
    {
        return new ProductTypeLifecycleCommand($this->context($request, "product-type:{$action->value}:{$productType->id}", $productType), $productType->id, $productType->brand_id, $action);
    }

    private function context(Request $request, string $fallbackKey, ?ProductType $current = null): MutationContext
    {
        return new MutationContext($this->getOrganizationId(), $request->user()?->id, $request->header('X-Correlation-ID') ?: (string) Str::uuid(), $request->header('Idempotency-Key') ?: $fallbackKey);
    }
}
