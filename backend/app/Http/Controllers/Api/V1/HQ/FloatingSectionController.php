<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasBulkOperations;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\FloatingSectionAddProductsRequest;
use App\Http\Requests\FloatingSectionStoreRequest;
use App\Http\Requests\FloatingSectionUpdateRequest;
use App\Http\Resources\FloatingSectionProductResource;
use App\Http\Resources\FloatingSectionProductSkuResource;
use App\Http\Resources\FloatingSectionResource;
use App\Models\Brand;
use App\Models\FloatingSection;
use App\Models\Organization;
use App\Services\Product\FloatingSectionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class FloatingSectionController extends Controller
{
    use AuthorizesRequests;
    use HasBulkOperations;
    use HasOrganizationContext;

    public function __construct(
        private readonly FloatingSectionService $service,
    ) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/floating-sections',
        summary: 'List floating sections',
        description: 'Returns floating sections for the brand, optionally filtered by search or active status.',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FloatingSection::class);

        $sections = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            // HQ manages the master catalog only — branch clones are managed
            // from the shop side (Api\V1\Shop\FloatingSectionController).
            'master_only' => true,
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return FloatingSectionResource::collection($sections);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/floating-sections',
        summary: 'Create a floating section',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 255),
                new OA\Property(property: 'is_active', type: 'boolean', default: true),
                new OA\Property(property: 'priority', type: 'integer', default: 0),
                new OA\Property(property: 'start_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function store(FloatingSectionStoreRequest $request): JsonResponse
    {
        $this->authorize('create', FloatingSection::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['brand_id'] = $request->attributes->get('brand_id');

        $section = $this->service->create($data);

        return (new FloatingSectionResource($section))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}',
        summary: 'Get a floating section',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Floating section'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(FloatingSection $floatingSection): FloatingSectionResource
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('view', $floatingSection);

        return new FloatingSectionResource(
            $this->service->findById($floatingSection->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}',
        summary: 'Update a floating section',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function update(FloatingSectionUpdateRequest $request, FloatingSection $floatingSection): FloatingSectionResource
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $floatingSection);

        $section = $this->service->update($floatingSection, $request->validated());

        return new FloatingSectionResource($section);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}',
        summary: 'Delete a floating section',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function destroy(FloatingSection $floatingSection): JsonResponse
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('delete', $floatingSection);

        $this->service->delete($floatingSection);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/clone-to-branch',
        summary: 'Clone a master floating section to a branch',
        description: 'Creates an independent branch copy (schedules + products) the shop then manages on its own — no ongoing sync from the master.',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['branch_id'],
            properties: [new OA\Property(property: 'branch_id', type: 'string', format: 'uuid')]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Branch clone created'),
            new OA\Response(response: 422, description: 'Already cloned to this branch, or not a master section'),
        ],
    )]
    public function cloneToBranch(Request $request, FloatingSection $floatingSection): JsonResponse
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('create', FloatingSection::class);

        $validated = $request->validate([
            'branch_id' => [
                'required', 'string', 'uuid',
                // Tenant isolation — mirrors MenuController::cloneToBranch.
                Rule::exists('branches', 'id')->where(
                    fn ($query) => $query
                        ->where('console_organization_id', Organization::whereKey($floatingSection->organization_id)->value('console_organization_id'))
                        ->where('console_brand_id', Brand::whereKey($floatingSection->brand_id)->value('console_brand_id'))
                        ->whereNull('deleted_at'),
                ),
            ],
        ]);

        $branchSection = $this->service->cloneToBranch($floatingSection, $validated['branch_id']);

        return (new FloatingSectionResource($branchSection))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/duplicate',
        summary: 'Duplicate a master floating section',
        description: 'Creates an independent copy (schedules + products + per-SKU pricing) — no link back to the source.',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Duplicate created'),
            new OA\Response(response: 422, description: 'Not a master section'),
        ],
    )]
    public function duplicate(FloatingSection $floatingSection): JsonResponse
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('create', FloatingSection::class);

        $copy = $this->service->duplicate($floatingSection);

        return (new FloatingSectionResource($copy))
            ->response()
            ->setStatusCode(201);
    }

    // =========================================================================
    //  Products
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/products',
        summary: 'Add products to a floating section',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['product_ids'],
            properties: [
                new OA\Property(property: 'product_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Products added'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function addProducts(FloatingSectionAddProductsRequest $request, FloatingSection $floatingSection): JsonResponse
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $floatingSection);

        $products = $this->service->addProducts($floatingSection, $request->validated('product_ids'));

        return FloatingSectionProductResource::collection($products)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}',
        summary: 'Remove a product from a floating section',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'floatingSectionProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Removed'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function removeProduct(FloatingSection $floatingSection, string $floatingSectionProduct): JsonResponse
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $floatingSection);

        $product = $floatingSection->products()->findOrFail($floatingSectionProduct);
        $this->service->removeProduct($product);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/toggle',
        summary: 'Toggle a floating section product active/inactive',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'floatingSectionProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Toggled')],
    )]
    public function toggleProduct(FloatingSection $floatingSection, string $floatingSectionProduct): FloatingSectionProductResource
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $floatingSection);

        $product = $floatingSection->products()->findOrFail($floatingSectionProduct);
        $toggled = $this->service->toggleProduct($product);

        return new FloatingSectionProductResource($toggled);
    }

    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/tax-type',
        summary: 'Set (or clear) the per-item tax-type override on a floating section product',
        description: 'null = inherit from the product. Parity with the menu-item tax-type override.',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'floatingSectionProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 422, description: 'Invalid tax_type_id'),
        ],
    )]
    public function updateProductTaxType(Request $request, FloatingSection $floatingSection, string $floatingSectionProduct): FloatingSectionProductResource
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $floatingSection);

        $validated = $request->validate([
            'tax_type_id' => ['present', 'nullable', 'uuid'],
        ]);

        $product = $floatingSection->products()->findOrFail($floatingSectionProduct);
        $updated = $this->service->updateProductTaxType(
            $product,
            $validated['tax_type_id'],
            $request->attributes->get('brand_id'),
        );

        return new FloatingSectionProductResource($updated);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/toggle',
        summary: 'Toggle a single SKU active/inactive within a floating section product',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'floatingSectionProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Toggled')],
    )]
    public function toggleProductSku(FloatingSection $floatingSection, string $floatingSectionProduct, string $sku): FloatingSectionProductSkuResource
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $floatingSection);

        $product = $floatingSection->products()->findOrFail($floatingSectionProduct);
        $skuRow = $product->skus()->findOrFail($sku);
        $toggled = $this->service->toggleProductSku($skuRow);

        return new FloatingSectionProductSkuResource($toggled);
    }

    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/price',
        summary: 'Override the promotional price of a single SKU',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'floatingSectionProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['selling_price'],
            properties: [new OA\Property(property: 'selling_price', type: 'number')]
        )),
        responses: [new OA\Response(response: 200, description: 'Price overridden')],
    )]
    public function overrideSkuPrice(Request $request, FloatingSection $floatingSection, string $floatingSectionProduct, string $sku): FloatingSectionProductSkuResource
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $floatingSection);

        $validated = $request->validate(['selling_price' => ['required', 'numeric', 'min:0']]);

        $product = $floatingSection->products()->findOrFail($floatingSectionProduct);
        $skuRow = $product->skus()->findOrFail($sku);
        $updated = $this->service->overrideSkuPrice($skuRow, (float) $validated['selling_price']);

        return new FloatingSectionProductSkuResource($updated);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/price/reset',
        summary: "Reset a SKU's price back to the product's default",
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'floatingSectionProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Price reset')],
    )]
    public function resetSkuPrice(FloatingSection $floatingSection, string $floatingSectionProduct, string $sku): FloatingSectionProductSkuResource
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $floatingSection);

        $product = $floatingSection->products()->findOrFail($floatingSectionProduct);
        $skuRow = $product->skus()->findOrFail($sku);
        $updated = $this->service->resetSkuPrice($skuRow);

        return new FloatingSectionProductSkuResource($updated);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/floating-sections/{floatingSection}/products/reorder',
        summary: 'Reorder products in a floating section',
        tags: ['Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ordered_ids'],
            properties: [
                new OA\Property(property: 'ordered_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Reordered'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function reorderProducts(Request $request, FloatingSection $floatingSection): FloatingSectionResource
    {
        $this->authorizeOrganization($floatingSection);
        $this->authorizeBrand($floatingSection);
        $this->authorize('update', $floatingSection);

        $validated = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['required', 'uuid'],
        ]);

        $section = $this->service->reorderProducts($floatingSection, $validated['ordered_ids']);

        return new FloatingSectionResource($section);
    }

    // =========================================================================
    //  Bulk Operations
    // =========================================================================

    protected function getModelClass(): string
    {
        return FloatingSection::class;
    }

    /**
     * HQ only ever bulk-deletes master sections — a branch's own clone is
     * managed from the shop side, mirroring the master_only filter on index().
     *
     * @return array<string, mixed>
     */
    protected function getBulkDeleteExtraScope(): array
    {
        return ['branch_id' => null];
    }
}
