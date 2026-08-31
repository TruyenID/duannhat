<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Resources\MenuProductToppingItemOverrideResource;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\ToppingGroup;
use App\Services\Topping\ShopMenuToppingOverrideService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Shop-level topping extra_price / visibility overrides.
 *
 * Mounted under:
 *   /api/v1/shops/{shopSlug}/menus/{menu}/products/{menuProduct}/topping-groups/{toppingGroup}/overrides
 *
 * Resolution priority (highest wins):
 *   1. These overrides (shop, menu_product_id scoped)
 *   2. product_topping_group_item_overrides (HQ per-product)
 *   3. topping_group_item_skus.extra_price  (HQ base)
 */
class ShopMenuToppingOverrideController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ShopMenuToppingOverrideService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/products/{menuProduct}/topping-groups/{toppingGroup}/overrides',
        summary: 'List shop-level topping overrides for a menu product',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
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
        [$menuProduct, $group] = $this->resolve($request);
        $this->authorize('shopView', $menuProduct->menu);

        return MenuProductToppingItemOverrideResource::collection(
            $this->service->list($menuProduct, $group)
        );
    }

    #[OA\Put(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/products/{menuProduct}/topping-groups/{toppingGroup}/overrides',
        summary: 'Sync shop-level topping overrides for a menu product',
        description: 'Replaces all shop-level overrides for this (menu_product, topping_group) pair. Pass an empty array to clear all overrides. Each row can set override_price (shop-specific add-on price) and/or is_hidden (hide the item from this branch\'s menu).',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
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
        [$menuProduct, $group] = $this->resolve($request);
        $this->authorize('shopUpdatePrice', $menuProduct->menu);

        $validated = $request->validate([
            // `present`, NOT `required` — this is a full-replace sync, so an
            // empty array is the legitimate way to say "no overrides left", and
            // Laravel's `required` rejects [] as empty. With `required` the last
            // override in a group could never be cleared: un-hiding it (or
            // clearing its price) drops the row client-side, leaving [] → 422
            // "The overrides field is required". Matches the HQ twin,
            // ProductToppingGroupItemOverrideSyncRequest.
            'overrides' => ['present', 'array'],
            'overrides.*.topping_group_item_id' => ['required', 'uuid'],
            'overrides.*.product_sku_id' => ['nullable', 'uuid'],
            'overrides.*.is_hidden' => ['required', 'boolean'],
            'overrides.*.override_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->service->sync($menuProduct, $group, $validated['overrides']);

        return MenuProductToppingItemOverrideResource::collection($result);
    }

    /** @return array{0: MenuProduct, 1: ToppingGroup} */
    private function resolve(Request $request): array
    {
        $shop = $request->attributes->get('shop');

        /** @var Menu $menu */
        $menu = Menu::where('branch_id', $shop->id)
            ->where('organization_id', $this->getOrganizationId())
            ->findOrFail((string) $request->route('menu'));

        /** @var MenuProduct $menuProduct */
        $menuProduct = $menu->menuProducts()
            ->findOrFail((string) $request->route('menuProduct'));

        /** @var ToppingGroup $group */
        // Scoped like the two lookups above it (#1265). This was a bare global
        // find by a URL-supplied id, three lines below a query that filters on
        // both branch_id and organization_id — so a shop could hang overrides
        // off another organization's topping group. HQ\ToppingGroupController
        // already scopes the same model this way.
        $group = ToppingGroup::query()
            ->where('organization_id', $this->getOrganizationId())
            ->findOrFail((string) $request->route('toppingGroup'));

        return [$menuProduct, $group];
    }
}
