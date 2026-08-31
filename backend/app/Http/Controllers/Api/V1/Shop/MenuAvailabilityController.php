<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\ToppingGroupItem;
use App\Services\Product\MenuService;
use App\Services\Product\ValueObjects\MenuAvailabilityActor;
use App\Services\Topping\ShopMenuToppingOverrideService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * plan-056 — the POS "Tồn món" screen: turn dishes and variants on/off.
 *
 * ## Why this is a separate controller and a separate URL namespace
 *
 * The ordering surface (`/pos/menus/*`) and this management surface answer two
 * different questions about the same rows: "what can the cashier sell right
 * now" versus "what is the shop's stock switchboard". They are kept apart on
 * purpose, and the reason is not tidiness:
 *
 *   · A shared endpoint with an `?include_inactive=1` flag puts both surfaces
 *     on ONE code path, where a single wrong default puts a sold-out dish back
 *     in the cart picker. Separate paths make that failure impossible rather
 *     than unlikely.
 *   · The two need different TanStack Query cache keys on the client. Sharing
 *     a key would let a management fetch (which includes turned-off rows)
 *     answer an ordering render from cache.
 *
 * ## What this controller may NOT do
 *
 * It never writes price. The shop's price override lives in admin-web and is
 * Manager-gated (`MenuPolicy::shopUpdatePrice`); this screen only READS the
 * numbers so staff can identify a variant by its price. Availability itself is
 * `shopUpdateAvailability`, i.e. any shop staff — a dish running out is not a
 * managerial decision, and requiring a manager mid-service is how shops end up
 * selling food they do not have.
 *
 * ## Turning something off is not a sell-block
 *
 * It hides the row from the ordering screen. It does NOT void lines already on
 * an open order, and it does NOT stop staff adding the dish by hand — that is
 * the pre-existing behaviour of `createItem` on the workstation, kept
 * deliberately: taking a dish offline mid-service must not break the order a
 * colleague is holding on another tablet.
 */
class MenuAvailabilityController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly MenuService $service,
        private readonly ShopMenuToppingOverrideService $toppings,
    ) {}

    // =========================================================================
    //  Read
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/pos/menu-availability/menus',
        summary: 'List the branch menus the POS availability screen can manage',
        description: 'Every branch menu of the resolved shop regardless of status — the screen manages stock, and a shop turns dishes off in a menu that is not currently the active one just as often.',
        tags: ['POS Menu Availability'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Menus', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $shop = $this->resolvedShop($request);

        $menus = Menu::query()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->orderBy('priority')
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'priority']);

        // One authorize() against any menu of the shop is the same check every
        // row would make — the policy is shop-scoped, not row-scoped.
        if ($menus->isNotEmpty()) {
            $this->authorize('shopView', $menus->first());
        }

        return response()->json([
            'data' => $menus->map(fn (Menu $m) => [
                'id' => (string) $m->id,
                'name' => (string) $m->name,
                'status' => (string) $m->status,
            ])->values()->all(),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/pos/menu-availability/menus/{menu}',
        summary: 'One menu with every dish and variant, INCLUDING the turned-off ones',
        description: 'The management shape. Unlike /pos/menus/{menu} this deliberately does NOT filter is_active — a screen that cannot see a turned-off dish cannot turn it back on. Prices are read-only.',
        tags: ['POS Menu Availability'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Menu with sections + products + variants'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $menu = $this->resolveMenu($request);
        $this->authorize('shopView', $menu);

        $menu->load([
            'menuSections:id,name',
            // NO is_active constraint anywhere in this eager-load. That is the
            // whole point of the endpoint.
            // #3170 — unique-id tie-break so this screen agrees with the menu
            // detail screen on the order of display_order-tied rows.
            'menuProducts' => fn ($q) => $q->orderBy('display_order')
                ->orderBy('menu_products.id'),
            'menuProducts.product',
            'menuProducts.menuSection:id,name',
            'menuProducts.menuProductSkus',
            'menuProducts.menuProductSkus.productSku',
            'menuProducts.menuProductSkus.productSku.optionValue1',
            'menuProducts.menuProductSkus.productSku.optionValue2',
            'menuProducts.menuProductSkus.productSku.optionValue3',
            // …and the option each value belongs to, so a variant can say which
            // AXIS it sits on ("Size") and not just which value ("Lớn"). Eager
            // here rather than lazily in `optionShapes`, which runs once per
            // variant of every dish on the menu.
            'menuProducts.menuProductSkus.productSku.optionValue1.option',
            'menuProducts.menuProductSkus.productSku.optionValue2.option',
            'menuProducts.menuProductSkus.productSku.optionValue3.option',
            // plan-056 — the topping layer. `toppingOverrides` is the SHOP tier
            // and is what decides the switch state; the groups/items come from
            // the product so a topping the shop has hidden still has a name to
            // render (an unnamed row is one nobody can switch back on).
            'menuProducts.toppingOverrides',
            'menuProducts.product.toppingGroups' => fn ($q) => $q
                ->where('topping_groups.is_active', true)
                ->orderBy('product_topping_groups.sort_order')
                ->orderBy('product_topping_groups.id'),
            'menuProducts.product.toppingGroups.items' => fn ($q) => $q
                ->orderBy('sort_order')
                ->orderBy('id'),
            'menuProducts.product.toppingGroups.items.product:id,name',
            // The per-variant add-on prices ("Ít bánh +0 ¥ / Nhiều bánh +120 ¥").
            // Read-only on this screen, but they are how staff tell one topping
            // variant from another — and the workstation already ships them, so
            // omitting them here would put the two servers out of step again.
            'menuProducts.product.toppingGroups.items.skus',
            'menuProducts.product.toppingGroups.items.skus.productSku:id,name,sku',
        ]);

        return response()->json([
            'data' => [
                'id' => (string) $menu->id,
                'name' => (string) $menu->name,
                'status' => (string) $menu->status,
                'sections' => $menu->menuSections
                    ->map(fn ($s) => ['id' => (string) $s->id, 'name' => (string) $s->name])
                    ->values()
                    ->all(),
                'products' => $menu->menuProducts
                    ->map(fn (MenuProduct $mp) => $this->productShape($mp))
                    ->values()
                    ->all(),
            ],
        ]);
    }

    // =========================================================================
    //  Write — SET, never toggle (replay-safe; see MenuService)
    // =========================================================================

    #[OA\Put(
        path: '/api/v1/pos/menu-availability/products/{menuProduct}',
        summary: 'Turn one dish on or off at this shop',
        tags: ['POS Menu Availability'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['is_active'],
            properties: [
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255, description: 'Free text. No minimum length is enforced.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Applied'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function setProductAvailability(Request $request): JsonResponse
    {
        $validated = $this->validateAvailabilityBody($request);
        $menuProduct = $this->resolveMenuProduct($request);
        $this->authorize('shopUpdateAvailability', $menuProduct->menu);

        $updated = $this->service->setProductActiveForShop(
            $menuProduct,
            (bool) $validated['is_active'],
            $this->actor($request),
            $validated['reason'] ?? null,
        );

        $updated->load([
            'product',
            'menuSection:id,name',
            'menuProductSkus.productSku.optionValue1',
            'menuProductSkus.productSku.optionValue2',
            'menuProductSkus.productSku.optionValue3',
            'menuProductSkus.productSku.optionValue1.option',
            'menuProductSkus.productSku.optionValue2.option',
            'menuProductSkus.productSku.optionValue3.option',
        ]);

        return response()->json(['data' => $this->productShape($updated)]);
    }

    #[OA\Put(
        path: '/api/v1/pos/menu-availability/skus/{menuProductSku}',
        summary: 'Turn one variant on or off at this shop',
        tags: ['POS Menu Availability'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'menuProductSku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['is_active'],
            properties: [
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Applied'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function setSkuAvailability(Request $request): JsonResponse
    {
        $validated = $this->validateAvailabilityBody($request);
        $sku = $this->resolveMenuProductSku($request);
        $this->authorize('shopUpdateAvailability', $sku->menuProduct->menu);

        $updated = $this->service->setSkuActiveForShop(
            $sku,
            (bool) $validated['is_active'],
            $this->actor($request),
            $validated['reason'] ?? null,
        );

        return response()->json(['data' => $this->skuShape($updated)]);
    }

    #[OA\Post(
        path: '/api/v1/pos/menu-availability/menus/{menu}/sections/{menuSection}/bulk',
        summary: 'Turn every dish of one section on or off',
        description: 'The "bật/tắt cả nhóm" button. Returns how many rows actually changed, which is what the confirmation toast reports.',
        tags: ['POS Menu Availability'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['is_active'],
            properties: [
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Applied', content: new OA\JsonContent(properties: [new OA\Property(property: 'updated', type: 'integer')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function bulkSetSectionAvailability(Request $request): JsonResponse
    {
        $validated = $this->validateAvailabilityBody($request);
        $menu = $this->resolveMenu($request);
        $this->authorize('shopUpdateAvailability', $menu);

        // Scope the section to this menu so a foreign section id 404s rather
        // than silently touching nothing.
        $sectionId = (string) $request->route('menuSection');
        $menu->menuSections()->findOrFail($sectionId);

        $updated = $this->service->setSectionProductsActiveForShop(
            $menu,
            $sectionId,
            (bool) $validated['is_active'],
            $this->actor($request),
            $validated['reason'] ?? null,
        );

        return response()->json(['updated' => $updated]);
    }

    #[OA\Post(
        path: '/api/v1/pos/menu-availability/menus/{menu}/skus/bulk',
        summary: 'Turn a set of variants on or off in one write',
        description: 'Backs "turn off size Lớn for this dish". Takes an EXPLICIT list of menu_product_sku ids because an option value is a name for a SET of variant rows — product_option_values has no branch column, so a write there would change every branch of the brand.',
        tags: ['POS Menu Availability'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['is_active', 'menu_product_sku_ids'],
            properties: [
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'menu_product_sku_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Applied — returns how many rows actually changed'),
            new OA\Response(response: 404, description: 'Menu not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function bulkSetSkuAvailability(Request $request): JsonResponse
    {
        $validated = $this->validateAvailabilityBody($request);
        $menu = $this->resolveMenu($request);
        $this->authorize('shopUpdateAvailability', $menu);

        $ids = $request->validate([
            'menu_product_sku_ids' => ['required', 'array', 'min:1', 'max:200'],
            'menu_product_sku_ids.*' => ['required', 'string', 'uuid'],
        ])['menu_product_sku_ids'];

        $updated = $this->service->setMenuProductSkusActiveForShop(
            $menu,
            array_values(array_unique(array_map('strval', $ids))),
            (bool) $validated['is_active'],
            $this->actor($request),
            $validated['reason'] ?? null,
        );

        return response()->json(['updated' => $updated]);
    }

    #[OA\Put(
        path: '/api/v1/pos/menu-availability/products/{menuProduct}/toppings/{toppingItem}',
        summary: 'Hide or show one topping on one dish',
        description: 'Writes a SINGLE shop override row. Deliberately not the admin sync endpoint, which replaces every override for the group and would wipe the shop topping prices nobody asked to touch.',
        tags: ['POS Menu Availability'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'toppingItem', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['is_active'],
            properties: [
                new OA\Property(property: 'is_active', type: 'boolean', description: 'The INVERSE of the stored is_hidden. One vocabulary on the wire for all three levels.'),
                new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Applied'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function setToppingAvailability(Request $request): JsonResponse
    {
        $validated = $this->validateAvailabilityBody($request);
        $menuProduct = $this->resolveMenuProduct($request);
        $this->authorize('shopUpdateAvailability', $menuProduct->menu);

        $item = ToppingGroupItem::query()->findOrFail((string) $request->route('toppingItem'));

        $isActive = (bool) $validated['is_active'];
        $changed = $this->toppings->setItemHiddenForShop($menuProduct, $item, ! $isActive);

        // Only a REAL change is logged — a replayed op is not an event.
        if ($changed) {
            $this->service->recordToppingAvailabilityEvent(
                menuProduct: $menuProduct,
                toppingGroupItemId: (string) $item->id,
                isActive: $isActive,
                actor: $this->actor($request),
                reason: $validated['reason'] ?? null,
            );
        }

        return response()->json(['data' => [
            'menu_product_id' => (string) $menuProduct->id,
            'topping_group_item_id' => (string) $item->id,
            'is_active' => $isActive,
        ]]);
    }

    // =========================================================================
    //  Internals
    // =========================================================================

    /**
     * @return array{is_active: bool, reason?: string|null}
     */
    private function validateAvailabilityBody(Request $request): array
    {
        // `reason` is nullable with a max and NO min. A cashier taps a preset
        // chip mid-service; rejecting "Hết" for being three characters would
        // cost service time and protect nothing. Over-long text is truncated in
        // MenuService rather than rejected, for the same reason — the toggle is
        // the point, the words are metadata.
        return $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * The acting cashier, from the SSO session.
     *
     * This is the CLOUD-DIRECT door (pos-web in cloud mode, or a shop with no
     * workstation), so there is a real authenticated user. The LAN door is
     * `Workstation\MenuAvailabilityController`, where the token proves the
     * terminal and the person has to be vetted separately.
     */
    private function actor(Request $request): MenuAvailabilityActor
    {
        $user = $request->user();

        return MenuAvailabilityActor::fromPos(
            $user?->getKey() !== null ? (string) $user->getKey() : null,
            $user?->name,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function productShape(MenuProduct $mp): array
    {
        return [
            'id' => (string) $mp->id,
            'product_id' => (string) $mp->product_id,
            'menu_section_id' => $mp->menu_section_id !== null ? (string) $mp->menu_section_id : null,
            'section_name' => $mp->menuSection?->name,
            'display_order' => (int) ($mp->display_order ?? 0),
            // Astrotomic resolves this against Accept-Language; a menu row whose
            // product was soft-deleted after the fact still renders rather than
            // 500ing, which is exactly the row a shop most wants to switch off.
            'name' => (string) ($mp->product?->name ?? ''),
            'image_url' => $mp->product?->image_url,
            'is_active' => (bool) $mp->is_active,
            'disabled_reason' => $mp->disabled_reason,
            'disabled_at' => $mp->disabled_at?->toIso8601String(),
            'disabled_by_name' => $mp->disabled_by_name,
            'skus' => $mp->relationLoaded('menuProductSkus')
                ? $mp->menuProductSkus->map(fn (MenuProductSku $s) => $this->skuShape($s))->values()->all()
                : [],
            'topping_groups' => $this->toppingGroupShapes($mp),
        ];
    }

    /**
     * Topping groups of one dish, each item carrying whether the SHOP has it
     * hidden.
     *
     * The rule is "ANY shop override row for the item says hidden", matching
     * `MenuToppingGroupItemResource` and the workstation resolver. Not "the
     * first row": rows come back unordered, and a dish can carry both a
     * wildcard row and a per-SKU one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function toppingGroupShapes(MenuProduct $mp): array
    {
        if (! $mp->relationLoaded('product') || $mp->product === null
            || ! $mp->product->relationLoaded('toppingGroups')) {
            return [];
        }

        $hiddenItemIds = $mp->relationLoaded('toppingOverrides')
            ? $mp->toppingOverrides
                ->filter(fn ($ov) => (bool) $ov->is_hidden)
                ->pluck('topping_group_item_id')
                ->flip()
            : collect();

        return $mp->product->toppingGroups->map(fn ($group) => [
            'id' => (string) $group->id,
            'name' => (string) $group->name,
            'items' => $group->relationLoaded('items')
                ? $group->items->map(fn ($item) => [
                    'id' => (string) $item->id,
                    'name' => (string) ($item->product?->name ?? ''),
                    // `is_active`, not `is_hidden`: one vocabulary across dishes,
                    // variants and toppings so the client never has to remember
                    // which level inverts. The DB column stays `is_hidden`.
                    'is_active' => ! $hiddenItemIds->has((string) $item->id),
                    // READ-ONLY detail, shown when the row is expanded. Field
                    // names mirror the workstation's topping-sku shape
                    // (`sku_label` / `sku_code` / `extra_price`) so one client
                    // parses both transports.
                    'skus' => $item->relationLoaded('skus')
                        ? $item->skus->map(fn ($sku) => [
                            'id' => (string) $sku->id,
                            'product_sku_id' => $sku->product_sku_id !== null ? (string) $sku->product_sku_id : null,
                            'sku_label' => $sku->productSku?->name,
                            'sku_code' => $sku->productSku?->sku,
                            // A STRING, matching the workstation. Add-on prices
                            // are money; a float here and an int there is how a
                            // client ends up formatting one of them wrong.
                            'extra_price' => (string) (int) round((float) ($sku->extra_price ?? 0)),
                        ])->values()->all()
                        : [],
                ])->values()->all()
                : [],
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function skuShape(MenuProductSku $sku): array
    {
        $productSku = $sku->productSku;

        return [
            // The menu_product_sku UUID — the address a write goes back to.
            // NOT product_sku_id: the same catalog SKU can sit in several menus
            // with different availability, and writing by catalog id would flip
            // all of them at once.
            'id' => (string) $sku->id,
            'product_sku_id' => (string) $sku->product_sku_id,
            'sku_code' => $productSku?->sku,
            'variant_label' => $this->variantLabel($sku),
            // The option AXES this variant sits on — "Size: Lớn", "Cay: Không".
            // The client groups the dish's variants by these to offer one
            // switch per option VALUE ("turn off Lớn"), which then writes every
            // menu_product_sku carrying it. There is no shop-scoped row for an
            // option value itself, so the value is a NAME FOR A SET, and this
            // is the set membership the client needs to build it.
            'options' => $this->optionShapes($sku),
            // READ-ONLY. There is no endpoint in this namespace that writes
            // either of these; price is admin-web's, and Manager-gated.
            'selling_price' => (float) $sku->selling_price,
            'default_price' => $productSku !== null ? (float) $productSku->selling_price : null,
            'is_price_overridden' => (bool) $sku->is_price_overridden,
            'is_active' => (bool) $sku->is_active,
            'disabled_reason' => $sku->disabled_reason,
            'disabled_at' => $sku->disabled_at?->toIso8601String(),
            'disabled_by_name' => $sku->disabled_by_name,
        ];
    }

    /**
     * The option axes one variant sits on, in `position` order.
     *
     * Up to three, because `product_skus` carries exactly three option-value
     * columns. Emitted with BOTH ids and labels: the id is what makes two
     * variants share an axis value (the client groups on it), the label is what
     * a cashier reads. Grouping on the label would merge two different values
     * that happen to be spelled the same in different option groups.
     *
     * @return list<array<string, mixed>>
     */
    private function optionShapes(MenuProductSku $sku): array
    {
        $productSku = $sku->productSku;
        if ($productSku === null) {
            return [];
        }

        $out = [];
        foreach ([$productSku->optionValue1, $productSku->optionValue2, $productSku->optionValue3] as $value) {
            if ($value === null) {
                continue;
            }
            $option = $value->option ?? null;
            $out[] = [
                'option_id' => $option !== null ? (string) $option->id : null,
                'option_name' => $option?->name ?? $option?->key,
                'value_id' => (string) $value->id,
                'value_label' => $value->label ?? $value->value,
                'position' => (int) ($option->position ?? 0),
            ];
        }

        return $out;
    }

    /**
     * "Lớn · Cay" — the option values that identify one variant, joined.
     *
     * admin-web spreads these over up to three table columns; a POS tablet has
     * no room for that, and the cashier only needs to tell the rows apart.
     * Falls back to the SKU's own name when a product has no option axes.
     */
    private function variantLabel(MenuProductSku $sku): ?string
    {
        $productSku = $sku->productSku;
        if ($productSku === null) {
            return null;
        }

        $parts = array_values(array_filter([
            $productSku->optionValue1?->label,
            $productSku->optionValue2?->label,
            $productSku->optionValue3?->label,
        ], fn ($v) => is_string($v) && trim($v) !== ''));

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        $name = $productSku->name;

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    private function resolvedShop(Request $request): Branch
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        return $shop;
    }

    private function resolveMenu(Request $request): Menu
    {
        $shop = $this->resolvedShop($request);

        return Menu::query()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail((string) $request->route('menu'));
    }

    /**
     * Resolve {menuProduct} scoped to the SHOP, not to a menu in the URL.
     *
     * The write routes carry no {menu} segment: a POS row already knows its
     * menu_product id, and threading the menu through would let a caller pass a
     * mismatched pair. Scoping through `menus.branch_id` here means an id from
     * another shop 404s, which is the boundary that actually matters.
     */
    private function resolveMenuProduct(Request $request): MenuProduct
    {
        $shop = $this->resolvedShop($request);

        return MenuProduct::query()
            ->whereHas('menu', fn ($q) => $q
                ->where('organization_id', $this->getOrganizationId())
                ->where('branch_id', $shop->id))
            ->with('menu')
            ->findOrFail((string) $request->route('menuProduct'));
    }

    private function resolveMenuProductSku(Request $request): MenuProductSku
    {
        $shop = $this->resolvedShop($request);

        return MenuProductSku::query()
            ->whereHas('menuProduct.menu', fn ($q) => $q
                ->where('organization_id', $this->getOrganizationId())
                ->where('branch_id', $shop->id))
            ->with('menuProduct.menu')
            ->findOrFail((string) $request->route('menuProductSku'));
    }
}
