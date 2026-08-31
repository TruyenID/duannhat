<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasBulkOperations;
use App\Http\Controllers\Traits\HasImportExport;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\DomainMutation\MutationContext;
use App\Services\Export\CsvExporter;
use App\Services\Export\ProductExporter;
use App\Services\Import\CsvImporter;
use App\Services\Import\ProductImporter;
use App\Services\Product\Commands\CreateProductCommand;
use App\Services\Product\Commands\ProductLifecycleCommand;
use App\Services\Product\Commands\ReviseProductCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\Enums\ProductLifecycleAction;
use App\Services\Product\ProductPayloadFactory;
use App\Services\Product\ProductQueryService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/products/import',
    summary: 'Import products from CSV',
    description: 'Bulk-imports products from a CSV file. Returns counts and per-row errors.',
    tags: ['Products'],
    security: [['sanctum' => []]],
    parameters: [
        new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
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
        new OA\Response(response: 200, description: 'Import succeeded (with optional partial errors)'),
        new OA\Response(response: 401, description: 'Unauthenticated'),
        new OA\Response(response: 422, description: 'Validation failed or all rows failed'),
    ]
)]
#[OA\Get(
    path: '/api/v1/hq/{brandSlug}/products/export',
    summary: 'Export products to CSV',
    description: 'Streams a CSV download of products for the brand.',
    tags: ['Products'],
    security: [['sanctum' => []]],
    parameters: [
        new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(response: 401, description: 'Unauthenticated'),
    ]
)]
#[OA\Get(
    path: '/api/v1/hq/{brandSlug}/products/import/template',
    summary: 'Download CSV import template',
    description: 'Returns an empty CSV template with the correct headers for product import.',
    tags: ['Products'],
    security: [['sanctum' => []]],
    parameters: [
        new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'CSV template', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(response: 401, description: 'Unauthenticated'),
    ]
)]
#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/products/bulk-delete',
    summary: 'Bulk-delete products',
    description: 'Soft-deletes up to 100 products at once. Returns deleted count and any per-id errors.',
    tags: ['Products'],
    security: [['sanctum' => []]],
    parameters: [
        new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', maxItems: 100, items: new OA\Items(type: 'string', format: 'uuid')),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Bulk-delete result'),
        new OA\Response(response: 401, description: 'Unauthenticated'),
        new OA\Response(response: 422, description: 'Validation failed'),
    ]
)]
class ProductController extends Controller
{
    use AuthorizesRequests;
    use HasBulkOperations;
    use HasImportExport;
    use HasOrganizationContext;

    public function __construct(
        private readonly ProductMutationFacade $mutations,
        private readonly ProductQueryService $queries,
        private readonly ProductPayloadFactory $payloads,
        private readonly ProductImporter $importer,
        private readonly ProductExporter $exporter,
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
        return 'import_products_template';
    }

    /**
     * Override the trait's default export so the brand resolved from the URL
     * slug (via ResolveBrandFromSlug middleware) is injected into the filter
     * set. Without this, the exporter sees only `organization_id` and returns
     * products from every brand in the org — a parity bug versus the list
     * endpoint, which already scopes by `brand_id`.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = $request->all();
        $brandId = $request->attributes->get('brand_id');
        if ($brandId) {
            $filters['brand_id'] = $brandId;
        }

        return $this->exporter->export($this->getOrganizationId(), $filters);
    }

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/products',
        summary: 'List products',
        description: 'Returns a paginated list of products for the brand. Supports search, status filter, sort, and pagination.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'pending', 'approved', 'active', 'inactive', 'rejected'])),
            new OA\Parameter(name: 'product_type_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'is_hidden', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'with_trashed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'only_trashed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-created_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of products',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Product')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $products = $this->queries->products($this->getOrganizationId(), $request->attributes->get('brand_id'), [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'product_type_id' => $request->input('product_type_id'),
            'category_id' => $request->input('category_id'),
            'is_hidden' => $request->has('is_hidden') ? $request->boolean('is_hidden') : null,
            'with_trashed' => $request->boolean('with_trashed', false),
            'only_trashed' => $request->boolean('only_trashed', false),
            'sort' => $request->input('sort', '-created_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return ProductResource::collection($products);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products',
        summary: 'Create a product',
        description: 'Creates a new product in the brand. Status defaults to "draft". Supports a Shopify-style nested create: pass `options[]` with their `values[]` and `skus[]` carrying `value_indices[]` to create the product, options, option values, and SKUs in a single transaction. When `options` is omitted, the request behaves as a quick-create and a single default SKU is generated automatically.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'product_type_id'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'product_type_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'slug', type: 'string', maxLength: 191, nullable: true, description: 'Auto-generated from name when omitted'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'inactive'], nullable: true),
                    new OA\Property(property: 'is_hidden', type: 'boolean', nullable: true),
                    new OA\Property(property: 'category_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
                    new OA\Property(
                        property: 'gallery_file_ids',
                        type: 'array',
                        nullable: true,
                        maxItems: 20,
                        description: 'Ordered list of temp File UUIDs (returned by POST /api/v1/files/upload). Backend attaches them as permanent files in the `gallery` collection on this product. Index 0 becomes the main image (sort_order 0). Used by the staged-create flow on /products/new.',
                        items: new OA\Items(type: 'string', format: 'uuid'),
                    ),
                    new OA\Property(
                        property: 'options',
                        type: 'array',
                        nullable: true,
                        maxItems: 3,
                        description: 'Up to 3 product options (Size, Color, etc). When present, the request is treated as a Shopify-style full create.',
                        items: new OA\Items(
                            type: 'object',
                            required: ['key', 'name', 'position', 'values'],
                            properties: [
                                new OA\Property(property: 'key', type: 'string', maxLength: 60, description: 'Lowercase alphanumeric + underscore. e.g. "size"'),
                                new OA\Property(property: 'name', type: 'string', maxLength: 120, description: 'Display name. e.g. "Size"'),
                                new OA\Property(property: 'position', type: 'integer', enum: [1, 2, 3], description: 'Stable column position. Determines which option_value{N}_id slot the SKU lands in.'),
                                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                                new OA\Property(
                                    property: 'values',
                                    type: 'array',
                                    minItems: 1,
                                    items: new OA\Items(
                                        type: 'object',
                                        required: ['value', 'label'],
                                        properties: [
                                            new OA\Property(property: 'value', type: 'string', maxLength: 60, description: 'Lowercase alphanumeric + underscore + hyphen. e.g. "s"'),
                                            new OA\Property(property: 'label', type: 'string', maxLength: 120, description: 'Display label. e.g. "S"'),
                                            new OA\Property(property: 'position', type: 'integer', minimum: 1, nullable: true),
                                            new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                                        ],
                                    ),
                                ),
                            ],
                        ),
                    ),
                    new OA\Property(
                        property: 'skus',
                        type: 'array',
                        nullable: true,
                        description: 'When `options` is also present, each SKU references option values by `value_indices` (one entry per option, in input order, 0-based index into that option\'s values array). When `options` is omitted, this field accepts the legacy quick-create SKU shape.',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'value_indices',
                                    type: 'array',
                                    items: new OA\Items(type: 'integer', minimum: 0),
                                    description: 'Required when posting alongside `options`. Length must equal the number of options. Each element is the 0-based index into the corresponding option\'s `values` array.',
                                ),
                                new OA\Property(property: 'sku', type: 'string', maxLength: 50, nullable: true, description: 'Auto-generated when omitted'),
                                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                                new OA\Property(property: 'selling_price', type: 'number', format: 'float', minimum: 0, nullable: true, description: 'Menu price shown to customers. Mirrors cost_price when omitted (legacy fallback).'),
                                new OA\Property(property: 'cost_price', type: 'number', format: 'float', minimum: 0, nullable: true, description: 'Defaults to 0; auto-derived from recipe/material when is_cost_override is false'),
                                new OA\Property(property: 'is_cost_override', type: 'boolean', nullable: true, description: 'Set true when cost_price is a manual override of cost_price_auto'),
                                new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                            ],
                        ),
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Product created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(ProductStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $brandId = $request->attributes->get('brand_id');
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey !== null) {
            $idempotencyKey = trim($idempotencyKey);
            if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Idempotency-Key must be a non-empty string of at most 255 bytes.'],
                ]);
            }
        }
        $productId = $idempotencyKey === null
            ? (string) Str::uuid()
            : (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "product:create:{$this->getOrganizationId()}:{$brandId}:{$idempotencyKey}");
        $payload = $this->payloads->forCreate($request->validated(), identitySeed: $idempotencyKey === null ? null : $productId);
        $result = $this->mutations->create(new CreateProductCommand(
            $this->mutationContext($request, "product:create:{$productId}", $idempotencyKey),
            $productId,
            $brandId,
            $payload,
            $payload->fingerprint(),
        ));
        $product = $this->queries->product($this->getOrganizationId(), $brandId, $productId);

        return (new ProductResource($product))
            ->response()
            ->setStatusCode($result->changed ? 201 : 200);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/products/{product}',
        summary: 'Get a product',
        description: 'Returns a single product by UUID, including relations.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Product not found'),
        ]
    )]
    public function show(Request $request): ProductResource
    {
        $product = $this->resolveProduct($request);
        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('view', $product);

        return new ProductResource(
            $this->queries->product($this->getOrganizationId(), $product->brand_id, $product->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/products/{product}',
        summary: 'Update a product',
        description: 'Updates a product. All fields are nullable for partial updates.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'product_type_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'sku', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'status', type: 'string', nullable: true),
                new OA\Property(property: 'is_hidden', type: 'boolean', nullable: true),
                new OA\Property(property: 'category_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Product updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(ProductUpdateRequest $request): ProductResource
    {
        $product = $this->resolveProduct($request);
        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('update', $product);

        $payload = $this->payloads->forRevision($product, $request->validated());
        $this->mutations->revise(new ReviseProductCommand(
            $this->mutationContext($request, "product:revise:{$product->id}"),
            $product->id,
            $product->brand_id,
            $payload,
            $payload->fingerprint(),
        ));
        $product = $this->queries->product($this->getOrganizationId(), $product->brand_id, $product->id);

        return new ProductResource($product);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/products/{product}',
        summary: 'Delete a product',
        description: 'Soft-deletes a product. Use the restore endpoint to recover.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Product deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Product not found'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $product = $this->resolveProduct($request);

        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('delete', $product);

        $this->mutations->archive($this->lifecycleCommand($request, $product, ProductLifecycleAction::Archive));

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products/{product}/restore',
        summary: 'Restore a soft-deleted product',
        description: 'Restores a previously soft-deleted product.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product restored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Product not found'),
        ]
    )]
    public function restore(Request $request): ProductResource
    {
        $resolved = $request->route('product');
        $id = $resolved instanceof Product ? $resolved->id : $resolved;

        $product = $this->queries->product($this->getOrganizationId(), $request->attributes->get('brand_id'), $id, true);

        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('restore', $product);

        $this->mutations->restore($this->lifecycleCommand($request, $product, ProductLifecycleAction::Restore));
        $product->refresh();

        return new ProductResource($product);
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/products/lookup',
        summary: 'Lookup products for select/combobox',
        description: 'Returns a lightweight list of products (id + label) for use in dropdowns and autocompletes.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lookup list', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        return response()->json([
            'data' => $this->queries->productLookup(
                $this->getOrganizationId(),
                $request->attributes->get('brand_id'),
            ),
        ]);
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products/{product}/submit-for-approval',
        summary: 'Submit product for approval',
        description: 'Transitions a draft product to pending status. Requires draft state.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function submitForApproval(Request $request): ProductResource
    {
        $product = $this->resolveProduct($request);
        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('submitForApproval', $product);

        $this->mutations->submit($this->lifecycleCommand($request, $product, ProductLifecycleAction::Submit));
        $product->refresh();

        return new ProductResource($product);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products/{product}/approve',
        summary: 'Approve a product',
        description: 'Approves a pending product. Records the approver and timestamp.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function approve(Request $request): ProductResource
    {
        $product = $this->resolveProduct($request);
        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('approve', $product);

        $this->mutations->approve($this->lifecycleCommand($request, $product, ProductLifecycleAction::Approve));
        $product->refresh();

        return new ProductResource($product);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products/{product}/reject',
        summary: 'Reject a product',
        description: 'Rejects a pending product with a required reason.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
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
            new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Validation failed or invalid state'),
        ]
    )]
    public function reject(Request $request): ProductResource
    {
        $product = $this->resolveProduct($request);
        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('reject', $product);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->mutations->reject($this->lifecycleCommand($request, $product, ProductLifecycleAction::Reject, $validated['rejection_reason']));
        $product->refresh();

        return new ProductResource($product);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products/{product}/activate',
        summary: 'Activate a product',
        description: 'Activates an approved product so it becomes available for sale.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Activated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function activate(Request $request): ProductResource
    {
        $product = $this->resolveProduct($request);
        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('activate', $product);

        $this->mutations->activate($this->lifecycleCommand($request, $product, ProductLifecycleAction::Activate));
        $product->refresh();

        return new ProductResource($product);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products/{product}/deactivate',
        summary: 'Deactivate a product',
        description: 'Deactivates a product so it is no longer available for sale.',
        tags: ['Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deactivated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
        ]
    )]
    public function deactivate(Request $request): ProductResource
    {
        $product = $this->resolveProduct($request);
        $this->authorizeOrganization($product);
        $this->authorizeBrand($product);
        $this->authorize('deactivate', $product);

        $this->mutations->deactivate($this->lifecycleCommand($request, $product, ProductLifecycleAction::Deactivate));
        $product->refresh();

        return new ProductResource($product);
    }

    // =========================================================================
    //  Bulk Operations
    // =========================================================================

    private function resolveProduct(Request $request): Product
    {
        $resolved = $request->route('product');

        $id = $resolved instanceof Product ? $resolved->id : $resolved;

        return $this->queries->routableProduct($id);
    }

    private function lifecycleCommand(Request $request, Product $product, ProductLifecycleAction $action, ?string $reason = null): ProductLifecycleCommand
    {
        $correlationId = $request->header('X-Correlation-ID') ?: (string) Str::uuid();
        $idempotencyKey = $request->header('Idempotency-Key') ?: "product:{$action->value}:{$product->id}:{$correlationId}";

        return new ProductLifecycleCommand(
            new MutationContext($this->getOrganizationId(), $request->user()?->id, $correlationId, $idempotencyKey),
            $product->id,
            $product->brand_id,
            $action,
            $reason,
        );
    }

    private function mutationContext(Request $request, string $fallbackKey, ?string $idempotencyKey = null): MutationContext
    {
        $correlationId = $request->header('X-Correlation-ID') ?: (string) Str::uuid();

        return new MutationContext(
            $this->getOrganizationId(),
            $request->user()?->id,
            $correlationId,
            $idempotencyKey ?? ($request->header('Idempotency-Key') ?: "{$fallbackKey}:{$correlationId}"),
        );
    }

    protected function getModelClass(): string
    {
        return Product::class;
    }
}
