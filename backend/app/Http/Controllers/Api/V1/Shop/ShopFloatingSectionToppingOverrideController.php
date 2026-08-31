<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Resources\FloatingSectionProductToppingItemOverrideResource;
use App\Models\Branch;
use App\Models\FloatingSection;
use App\Models\FloatingSectionProduct;
use App\Models\ToppingGroup;
use App\Services\Topping\ShopFloatingSectionToppingOverrideService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Shop-level topping extra_price / visibility overrides for a floating section
 * product — the tier-1 twin of ShopMenuToppingOverrideController.
 *
 * Mounted under:
 *   /api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/topping-groups/{toppingGroup}/overrides
 *
 * Same 3-tier resolution as the menu:
 *   1. these overrides (shop, floating_section_product_id scoped)
 *   2. product_topping_group_item_overrides (HQ per-product)
 *   3. topping_group_item_skus.extra_price  (HQ base)
 */
class ShopFloatingSectionToppingOverrideController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ShopFloatingSectionToppingOverrideService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/topping-groups/{toppingGroup}/overrides',
        summary: 'List shop-level topping overrides for a floating section product',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'floatingSectionProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'toppingGroup', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of overrides'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        [$floatingSection, $sectionProduct, $group] = $this->resolve($request);
        $this->authorize('shopView', $floatingSection);

        return FloatingSectionProductToppingItemOverrideResource::collection(
            $this->service->list($sectionProduct, $group)
        );
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/floating-sections/{floatingSection}/products/{floatingSectionProduct}/topping-groups/{toppingGroup}/overrides',
        summary: 'Sync shop-level topping overrides for a floating section product',
        description: 'Replaces all shop-level overrides for this (floating_section_product, topping_group) pair. Pass an empty array to clear all overrides.',
        tags: ['Shop Floating Sections'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floatingSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'floatingSectionProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'toppingGroup', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['overrides'],
                properties: [
                    new OA\Property(
                        property: 'overrides',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'topping_group_item_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid', nullable: true),
                                new OA\Property(property: 'is_hidden', type: 'boolean'),
                                new OA\Property(property: 'override_price', type: 'number', nullable: true, minimum: 0),
                            ],
                            type: 'object'
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated overrides'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function sync(Request $request): AnonymousResourceCollection
    {
        [$floatingSection, $sectionProduct, $group] = $this->resolve($request);
        $this->authorize('shopUpdatePrice', $floatingSection);

        $validated = $request->validate([
            // `present`, NOT `required` — see the identical note on
            // ShopMenuToppingOverrideController::sync. Full-replace sync, so []
            // means "no overrides left"; `required` treats [] as empty and made
            // the last override in a group impossible to clear.
            'overrides' => ['present', 'array'],
            'overrides.*.topping_group_item_id' => ['required', 'uuid'],
            'overrides.*.product_sku_id' => ['nullable', 'uuid'],
            'overrides.*.is_hidden' => ['required', 'boolean'],
            'overrides.*.override_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->service->sync($sectionProduct, $group, $validated['overrides']);

        return FloatingSectionProductToppingItemOverrideResource::collection($result);
    }

    /**
     * Resolve {floatingSection} → {floatingSectionProduct} → {toppingGroup} from
     * the route, scoped to the shop's own branch clone + the user's org so a
     * foreign id 404s rather than leaking existence.
     *
     * @return array{0: FloatingSection, 1: FloatingSectionProduct, 2: ToppingGroup}
     */
    private function resolve(Request $request): array
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        /** @var FloatingSection $floatingSection */
        $floatingSection = FloatingSection::query()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail((string) $request->route('floatingSection'));

        /** @var FloatingSectionProduct $sectionProduct */
        $sectionProduct = $floatingSection->products()
            ->findOrFail((string) $request->route('floatingSectionProduct'));

        /** @var ToppingGroup $group */
        // Scoped like the two lookups above it (#1265). This was a bare global
        // find by a URL-supplied id, three lines below a query that filters on
        // both branch_id and organization_id — so a shop could hang overrides
        // off another organization's topping group. HQ\ToppingGroupController
        // already scopes the same model this way.
        $group = ToppingGroup::query()
            ->where('organization_id', $this->getOrganizationId())
            ->findOrFail((string) $request->route('toppingGroup'));

        return [$floatingSection, $sectionProduct, $group];
    }
}
