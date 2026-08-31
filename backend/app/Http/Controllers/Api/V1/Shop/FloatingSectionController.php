<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Resources\FloatingSectionProductResource;
use App\Http\Resources\FloatingSectionProductSkuResource;
use App\Http\Resources\FloatingSectionResource;
use App\Models\Branch;
use App\Models\FloatingSection;
use App\Models\FloatingSectionProduct;
use App\Models\FloatingSectionProductSku;
use App\Services\Product\FloatingSectionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Shop-side floating section controller.
 *
 * Mirrors Shop\MenuController's shape: the shop only ever sees its own
 * branch clone (branch_id set via FloatingSection::cloneToBranch — see
 * Api\V1\HQ\FloatingSectionController::cloneToBranch), and may:
 *  - read its floating sections + their schedules/products
 *  - toggle a product active/inactive (any shop staff)
 *  - override / reset a product's promotional price (shop manager+)
 *  - view + toggle (on/off only) its own schedule windows — see
 *    Api\V1\Shop\FloatingSectionScheduleController. Unlike Menu, there is no
 *    COALESCE-override indirection — the clone's rows are the shop's own —
 *    but a pull-based sync (see syncFromMaster below) lets the shop fetch
 *    HQ's latest product set / schedule timing on demand.
 */
class FloatingSectionController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly FloatingSectionService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/floating-sections',
        summary: 'List floating sections cloned to this shop',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $shop = $this->resolvedShop($request);

        $sections = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'branch_id' => $shop->id,
            'search' => $request->input('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return FloatingSectionResource::collection($sections);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}',
        summary: 'Get a shop floating section',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Floating section'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Request $request): FloatingSectionResource
    {
        $floatingSection = $this->resolveFloatingSection($request);
        $this->authorize('shopView', $floatingSection);

        return new FloatingSectionResource(
            $this->service->findById($floatingSection->id)
        );
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/toggle',
        summary: 'Toggle a floating section product active/inactive',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'floatingSectionProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Toggled')],
    )]
    public function toggleProduct(Request $request): FloatingSectionProductResource
    {
        $floatingSection = $this->resolveFloatingSection($request);
        $this->authorize('shopUpdateAvailability', $floatingSection);

        $product = $this->resolveFloatingSectionProduct($floatingSection, $request);
        $toggled = $this->service->toggleProduct($product);

        return new FloatingSectionProductResource($toggled);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/toggle',
        summary: 'Toggle a single SKU active/inactive within a floating section product',
        description: 'e.g. one size temporarily out of stock. Any shop staff.',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'floatingSectionProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Toggled')],
    )]
    public function toggleSku(Request $request): FloatingSectionProductSkuResource
    {
        $floatingSection = $this->resolveFloatingSection($request);
        $this->authorize('shopUpdateAvailability', $floatingSection);

        $product = $this->resolveFloatingSectionProduct($floatingSection, $request);
        $sku = $this->resolveFloatingSectionProductSku($product, $request);
        $toggled = $this->service->toggleProductSku($sku);

        return new FloatingSectionProductSkuResource($toggled);
    }

    #[OA\Patch(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/price',
        summary: "Override a SKU's promotional price",
        description: 'Restricted to Shop Manager and above.',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
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
    public function overrideSkuPrice(Request $request): FloatingSectionProductSkuResource
    {
        $floatingSection = $this->resolveFloatingSection($request);
        $this->authorize('shopUpdatePrice', $floatingSection);

        $validated = $request->validate(['selling_price' => ['required', 'numeric', 'min:0']]);

        $product = $this->resolveFloatingSectionProduct($floatingSection, $request);
        $sku = $this->resolveFloatingSectionProductSku($product, $request);
        $updated = $this->service->overrideSkuPrice($sku, (float) $validated['selling_price']);

        return new FloatingSectionProductSkuResource($updated);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/price/reset',
        summary: "Reset a SKU's price back to the product's default",
        description: 'Restricted to Shop Manager and above.',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'floatingSectionProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Price reset')],
    )]
    public function resetSkuPrice(Request $request): FloatingSectionProductSkuResource
    {
        $floatingSection = $this->resolveFloatingSection($request);
        $this->authorize('shopUpdatePrice', $floatingSection);

        $product = $this->resolveFloatingSectionProduct($floatingSection, $request);
        $sku = $this->resolveFloatingSectionProductSku($product, $request);
        $updated = $this->service->resetSkuPrice($sku);

        return new FloatingSectionProductSkuResource($updated);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/sync',
        summary: 'Pull the latest product set + schedule timing from the HQ master',
        description: 'Preserves the shop\'s own is_active toggles and price overrides.',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Synced'),
            new OA\Response(response: 422, description: 'Not cloned from a master section'),
        ],
    )]
    public function syncFromMaster(Request $request): FloatingSectionResource
    {
        $floatingSection = $this->resolveFloatingSection($request);
        $this->authorize('shopUpdateAvailability', $floatingSection);

        $synced = $this->service->syncFromMaster($floatingSection);

        return new FloatingSectionResource($synced);
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    private function resolvedShop(Request $request): Branch
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return $shop;
    }

    /**
     * Resolve {floatingSection} from the route, scoped to the resolved shop's
     * branch AND the user's organization — a foreign section id 404s rather
     * than leaking existence.
     */
    private function resolveFloatingSection(Request $request): FloatingSection
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('floatingSection');

        return FloatingSection::query()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);
    }

    private function resolveFloatingSectionProduct(FloatingSection $floatingSection, Request $request): FloatingSectionProduct
    {
        $id = (string) $request->route('floatingSectionProduct');

        return $floatingSection->products()->findOrFail($id);
    }

    private function resolveFloatingSectionProductSku(FloatingSectionProduct $product, Request $request): FloatingSectionProductSku
    {
        $id = (string) $request->route('sku');

        return $product->skus()->findOrFail($id);
    }
}
