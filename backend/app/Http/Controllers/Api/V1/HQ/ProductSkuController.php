<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasImportExport;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ProductSkuStoreRequest;
use App\Http\Requests\ProductSkuUpdateRequest;
use App\Http\Resources\ProductSkuResource;
use App\Models\Product;
use App\Models\ProductSku;
use App\Omnify\Enums\ProductSkuInventoryModeEnum;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\Export\CsvExporter;
use App\Services\Export\ProductSkuExporter;
use App\Services\Import\CsvImporter;
use App\Services\Import\ProductSkuImporter;
use App\Services\Product\Commands\CreateProductSkuCommand;
use App\Services\Product\Commands\GenerateProductSkuCombinationsCommand;
use App\Services\Product\Commands\ProductSkuLifecycleCommand;
use App\Services\Product\Commands\ReviseProductSkuCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\Enums\ProductSkuLifecycleAction;
use App\Services\Product\ProductQueryService;
use App\Services\Product\ValueObjects\ProductSkuPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/skus/import',
    summary: 'Import product SKUs from CSV',
    description: 'Bulk-imports product SKUs from a CSV file.',
    tags: ['ProductSkus'],
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
    path: '/api/v1/hq/{brandSlug}/skus/export',
    summary: 'Export SKUs to CSV',
    description: 'Streams a CSV download of SKUs.',
    tags: ['ProductSkus'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
    ]
)]
#[OA\Get(
    path: '/api/v1/hq/{brandSlug}/skus/import/template',
    summary: 'Download SKU import template',
    description: 'Returns an empty CSV template for SKU import.',
    tags: ['ProductSkus'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'CSV template', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
    ]
)]
class ProductSkuController extends Controller
{
    use AuthorizesRequests;
    use HasImportExport;
    use HasOrganizationContext;

    public function __construct(
        private readonly ProductQueryService $queries,
        private readonly ProductMutationFacade $mutations,
        private readonly ProductSkuImporter $importer,
        private readonly ProductSkuExporter $exporter,
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
        return 'product_skus_import_template';
    }

    // plan-040 TI.1 (C6): HasImportExport authorizes import/export against this
    // model class. ProductSkuController doesn't use HasBulkOperations, so it
    // declares getModelClass() here directly.
    protected function getModelClass(): string
    {
        return ProductSku::class;
    }

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/products/{product}/skus',
        summary: 'List SKUs for a product',
        description: 'Returns a paginated list of SKUs belonging to the given product.',
        tags: ['ProductSkus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductSku')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
            new OA\Response(response: 404, description: 'Product not found'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $product = $this->resolveProduct($request);

        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('viewAny', ProductSku::class);

        $skus = $this->queries->skusForProduct($this->getOrganizationId(), $product->brand_id, $product->id, [
            'search' => $request->input('search'),
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return ProductSkuResource::collection($skus);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/skus',
        summary: 'List all SKUs in the brand',
        description: 'Returns a paginated list of all SKUs across products in the brand.',
        tags: ['ProductSkus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductSku')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
        ]
    )]
    public function indexAll(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProductSku::class);

        $skus = $this->queries->skus($this->getOrganizationId(), request()->attributes->get('brand_id'), [
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'product_id' => $request->input('product_id'),
            'with_trashed' => $request->boolean('with_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return ProductSkuResource::collection($skus);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products/{product}/skus',
        summary: 'Create a SKU for a product',
        description: 'Creates a new SKU under the specified product.',
        tags: ['ProductSkus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'sku', type: 'string', maxLength: 50, nullable: true),
                    new OA\Property(property: 'recipe_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductSku')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(ProductSkuStoreRequest $request): JsonResponse
    {
        $product = $this->resolveProduct($request);

        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('create', ProductSku::class);

        $skuId = (string) Str::uuid();
        $payload = $this->payload($request->validated(), $skuId);
        $this->mutations->createSku(new CreateProductSkuCommand($this->context($request, "sku:create:{$skuId}"), $product->id, $product->brand_id, $payload, $payload->fingerprint()));
        $sku = $this->queries->sku($this->getOrganizationId(), $product->brand_id, $skuId);

        return (new ProductSkuResource($sku))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products/{product}/skus/generate-combinations',
        summary: 'Generate missing SKU combinations',
        description: 'Cartesian-generates every option-value combination from the product\'s options that does not yet have a matching SKU. Refuses if the product has no options, any option has no values, or the combination count exceeds 500.',
        tags: ['ProductSkus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Combinations generated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductSku')),
                    new OA\Property(property: 'created_count', type: 'integer'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Validation failed (no options / no values / too many combinations)', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function generateCombinations(Request $request): JsonResponse
    {
        $product = $this->resolveProduct($request);

        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('create', ProductSku::class);

        $result = $this->mutations->generateSkuCombinations(new GenerateProductSkuCombinationsCommand($this->context($request, "sku:generate-combinations:{$product->id}"), $product->id, $product->brand_id));
        $created = $this->queries->skusByIds($this->getOrganizationId(), $product->brand_id, $result->skuIds);

        return response()->json([
            'data' => ProductSkuResource::collection($created),
            'created_count' => $created->count(),
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/skus/{sku}',
        summary: 'Get a SKU',
        description: 'Returns a single SKU by UUID.',
        tags: ['ProductSkus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SKU details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductSku')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request): ProductSkuResource
    {
        $sku = $this->resolveSku($request);
        $this->authorizeOrganization($sku->product);
        $this->authorize('view', $sku);

        return new ProductSkuResource(
            $this->queries->sku($this->getOrganizationId(), $sku->product->brand_id, $sku->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/skus/{sku}',
        summary: 'Update a SKU',
        description: 'Updates a SKU. Fields are nullable for partial updates.',
        tags: ['ProductSkus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'sku', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'recipe_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                new OA\Property(
                    property: 'inventory_mode',
                    type: 'string',
                    enum: ['made_to_order', 'track_stock'],
                    nullable: true,
                    description: 'Plan-024 — order-close stock deduction policy. made_to_order = no stock-out + no recipe-based material deduction. track_stock = emit sales + sales_material_consumption transactions at order close.'
                ),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductSku')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(ProductSkuUpdateRequest $request): ProductSkuResource
    {
        $sku = $this->resolveSku($request);
        $this->authorizeOrganization($sku->product);
        $this->authorize('update', $sku);

        $payload = $this->payload($request->validated(), $sku->id, $sku);
        $this->mutations->reviseSku(new ReviseProductSkuCommand($this->context($request, "sku:revise:{$sku->id}", $sku), $sku->product->brand_id, $payload, $payload->fingerprint()));
        $sku = $this->queries->sku($this->getOrganizationId(), $sku->product->brand_id, $sku->id);

        return new ProductSkuResource($sku);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/skus/{sku}',
        summary: 'Delete a SKU',
        description: 'Soft-deletes a SKU.',
        tags: ['ProductSkus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $sku = $this->resolveSku($request);
        $this->authorizeOrganization($sku->product);
        $this->authorize('delete', $sku);

        $this->mutations->archiveSku($this->lifecycle($request, $sku, ProductSkuLifecycleAction::Archive));

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/skus/{sku}/restore',
        summary: 'Restore a soft-deleted SKU',
        description: 'Restores a previously soft-deleted SKU.',
        tags: ['ProductSkus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductSku')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function restore(Request $request): ProductSkuResource
    {
        $skuId = $request->route('sku');

        $skuId = $skuId instanceof ProductSku ? $skuId->id : $skuId;
        $sku = $this->queries->sku($this->getOrganizationId(), request()->attributes->get('brand_id'), $skuId, true);

        $sku->loadMissing('product');
        $this->authorizeSkuScope($sku);
        $this->authorize('restore', $sku);

        $this->mutations->restoreSku($this->lifecycle($request, $sku, ProductSkuLifecycleAction::Restore));
        $sku->refresh();

        return new ProductSkuResource($sku);
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/SKUs/lookup',
        summary: 'Lookup SKUs',
        description: 'Returns a lightweight list of SKUs for select/combobox.',
        tags: ['ProductSkus'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        ]
    )]
    public function lookup(): JsonResponse
    {
        $this->authorize('viewAny', ProductSku::class);

        return response()->json([
            'data' => $this->queries->skuLookup(
                $this->getOrganizationId(),
                request()->attributes->get('brand_id'),
            ),
        ]);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/skus/{sku}/toggle-status',
        summary: 'Toggle SKU active status',
        description: 'Toggles the is_active flag on the SKU.',
        tags: ['ProductSkus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Toggled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProductSku')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function toggleStatus(Request $request): ProductSkuResource
    {
        $sku = $this->resolveSku($request);
        $this->authorizeOrganization($sku->product);
        $this->authorize('update', $sku);

        $this->mutations->toggleSkuStatus($this->lifecycle($request, $sku, ProductSkuLifecycleAction::ToggleStatus));
        $sku->refresh();

        return new ProductSkuResource($sku);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/skus/{sku}/check-usage',
        summary: 'Check usage of a SKU',
        description: 'Returns information about where the SKU is used (menus, orders, etc.).',
        tags: ['ProductSkus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Usage report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function checkUsage(Request $request): JsonResponse
    {
        $sku = $this->resolveSku($request);
        $this->authorizeOrganization($sku->product);
        $this->authorize('view', $sku);

        return response()->json([
            'data' => $this->queries->skuUsage($sku),
        ]);
    }

    private function resolveSku(Request $request): ProductSku
    {
        $sku = $request->route('sku');
        $skuId = $sku instanceof ProductSku ? $sku->id : $sku;
        $sku = $this->queries->sku($this->getOrganizationId(), request()->attributes->get('brand_id'), $skuId);
        $this->authorizeSkuScope($sku);

        return $sku;
    }

    private function resolveProduct(Request $request): Product
    {
        $product = $request->route('product');
        $productId = $product instanceof Product ? $product->id : $product;

        return $this->queries->product($this->getOrganizationId(), request()->attributes->get('brand_id'), $productId);
    }

    private function authorizeSkuScope(ProductSku $sku): void
    {
        $this->authorizeOrganization($sku->product);
        $this->authorizeBrand($sku->product);
    }

    /** @param array<string, mixed> $data */
    private function payload(array $data, string $skuId, ?ProductSku $current = null): ProductSkuPayload
    {
        $translations = [];
        $cleared = [];
        foreach (SupportedLocale::cases() as $locale) {
            $localized = $data[$locale->value] ?? null;
            if (is_array($localized) && trim((string) ($localized['name'] ?? '')) !== '') {
                $translations[] = new LocalizedText($locale, $localized['name']);
            } elseif (array_key_exists($locale->value, $data)) {
                $cleared[] = $locale->value;
            }
        }
        $optionIds = [];
        foreach (['option_value1_id', 'option_value2_id', 'option_value3_id'] as $field) {
            $id = array_key_exists($field, $data) ? $data[$field] : $current?->{$field};
            $optionIds[] = $id;
        }
        $mode = $data['inventory_mode'] ?? $current?->inventory_mode?->value ?? ProductSkuInventoryModeEnum::MadeToOrder->value;

        return new ProductSkuPayload($skuId, array_key_exists('sku', $data) ? $data['sku'] : $current?->sku, $data['selling_price'] ?? $current?->selling_price ?? '0', $optionIds, (bool) ($data['is_active'] ?? $current?->is_active ?? true), array_key_exists('name', $data) ? $data['name'] : $current?->name, array_key_exists('recipe_id', $data) ? $data['recipe_id'] : $current?->recipe_id, $data['recipe_multiplier'] ?? $current?->recipe_multiplier ?? '1', $data['cost_price'] ?? $current?->cost_price ?? '0', $data['cost_price_auto'] ?? $current?->cost_price_auto ?? '0', (bool) ($data['is_cost_override'] ?? $current?->is_cost_override ?? false), ProductSkuInventoryModeEnum::from($mode), $translations, [], $cleared);
    }

    private function lifecycle(Request $request, ProductSku $sku, ProductSkuLifecycleAction $action): ProductSkuLifecycleCommand
    {
        return new ProductSkuLifecycleCommand($this->context($request, "sku:{$action->value}:{$sku->id}", $sku), $sku->id, $sku->product->brand_id, $action);
    }

    private function context(Request $request, string $key, ?ProductSku $current = null): MutationContext
    {
        return new MutationContext($this->getOrganizationId(), $request->user()?->id, $request->header('X-Correlation-ID') ?: (string) Str::uuid(), $request->header('Idempotency-Key') ?: $key);
    }
}
