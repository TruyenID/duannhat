<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ShopOverrideSkuPriceRequest;
use App\Http\Resources\MenuProductResource;
use App\Http\Resources\MenuProductSkuResource;
use App\Http\Resources\MenuResource;
use App\Http\Resources\ShopMenuByDayResource;
use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuPromotion;
use App\Models\TaxType;
use App\Services\Customer\TaxResolver;
use App\Services\Product\MenuService;
use App\Services\Promotion\FloatingSectionPriceResolver;
use App\Services\Promotion\MenuPromotionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Shop-side menu controller.
 *
 * The shop sees only branch menus cloned from an HQ master menu, and may:
 *  - read menus and their products/SKUs
 *  - toggle product or SKU active/inactive
 *  - override per-shop selling price on a SKU, or reset it
 *  - sync new products from master menu
 */
class MenuController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly MenuService $service,
        private readonly MenuPromotionService $promotionService,
        private readonly FloatingSectionPriceResolver $floatingSectionPrices,
    ) {}

    // =========================================================================
    //  Read
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/menus',
        summary: 'List branch menus visible to the shop',
        description: 'Returns the branch menus belonging to the resolved shop. Only menus cloned from an HQ master menu are returned. Filterable by status; defaults to Active.',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Search by menu name'),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['Draft', 'Pending', 'Approved', 'Active', 'Inactive', 'Rejected'], default: 'Active')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of branch menus',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Menu')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Shop not found'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $shop = $this->resolvedShop($request);

        $menus = $this->service->listBranchMenusForShop($shop->id, [
            'search' => $request->input('search'),
            // No default — absence of the query param means "any status".
            // Callers that want only active menus must pass ?status=Active.
            'status' => $request->input('status'),
            // #481 — POS filters menus by the order's service type (DineIn /
            // Takeaway / Both), mirroring the customer-web split (#463). Absent
            // / invalid value → no gate (every menu), preserving old callers.
            'service_type' => $this->validatedServiceType($request),
            'per_page' => min($request->integer('per_page', 20), 100),
        ]);

        return MenuResource::collection($menus);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/menus/by-day/{dayOfWeek}',
        summary: 'List Active branch menus active on a given day-of-week',
        description: <<<'DESC'
        Returns Active branch menus that have an explicit schedule covering the given `dayOfWeek` (0=Sun … 6=Sat — Carbon convention). Status is hard-coded to `Active`.

        A menu is returned only when it has at least one active, non-deleted row in `menu_schedules` whose `days_of_week` bitmask includes that day. **Always-on menus (no schedule rows) are excluded** — this endpoint is strictly schedule-driven.

        Each row carries `start_time` and `end_time` from the highest-priority active schedule matching that day. The returned times are shop-effective — any `branch_schedule_overrides` for that (schedule, branch) pair supersedes the HQ default. Priority selection itself is HQ-owned; only the hours are override-aware. Time-of-day is NOT applied to the listing — use `GET /api/v1/customer/branches/{branchSlug}/menu` for the single live menu at the current moment.
        DESC,
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'dayOfWeek', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 0, maximum: 6), description: '0=Sun, 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat (Carbon convention).'),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Search by menu name'),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of Active branch menus active on the given day',
                content: new OA\JsonContent(properties: [
                    new OA\Property(
                        property: 'data',
                        type: 'array',
                        items: new OA\Items(allOf: [
                            new OA\Schema(ref: '#/components/schemas/Menu'),
                            new OA\Schema(properties: [
                                new OA\Property(property: 'start_time', type: 'string', format: 'time', example: '07:00:00', description: 'Start time of the highest-priority active schedule matching the requested day.'),
                                new OA\Property(property: 'end_time', type: 'string', format: 'time', example: '10:30:00', description: 'End time of the highest-priority active schedule matching the requested day.'),
                            ]),
                        ]),
                    ),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Shop not found or `dayOfWeek` out of range'),
        ],
    )]
    public function indexByDay(Request $request, int $dayOfWeek): AnonymousResourceCollection
    {
        $shop = $this->resolvedShop($request);

        $menus = $this->service->listActiveBranchMenusForShopByDay($shop->id, $dayOfWeek, [
            'search' => $request->input('search'),
            'service_type' => $this->validatedServiceType($request),
            'per_page' => min($request->integer('per_page', 20), 100),
        ]);

        return ShopMenuByDayResource::collection($menus);
    }

    /**
     * Whitelist the `service_type` query param to a valid menu service type
     * (#481). Anything else — including absent — resolves to null so the
     * listing applies no service-type gate.
     */
    private function validatedServiceType(Request $request): ?string
    {
        $value = $request->input('service_type');

        return in_array($value, ['DineIn', 'Takeaway', 'Both'], true) ? $value : null;
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}',
        summary: 'Show one branch menu with its products and SKUs',
        description: 'Returns a single menu eager-loaded with its products, each carrying menu product SKUs with pricing data and the related productSku.',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Menu with products', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Menu not found'),
        ],
    )]
    public function show(Request $request): MenuResource
    {
        $menu = $this->resolveMenu($request);
        $this->authorize('shopView', $menu);

        $compact = $request->boolean('compact');

        $menu->load([
            'menuSections',
            // Follow the master's item order (display_order) so a shop menu
            // renders products in the same sequence HQ arranged — syncFromMaster
            // mirrors display_order onto the branch rows, but without this
            // constraint the eager-load returns raw insertion order and the
            // reordered layout never surfaces on the shop detail screen.
            // #3170 — plus the unique-id tie-break: display_order is not a
            // total order, so the tied block would otherwise be free to move.
            'menuProducts' => fn ($q) => $q->orderBy('display_order')
                ->orderBy('menu_products.id'),
            'menuProducts.menuProductSkus.productSku',
            // Option value trio + their parent option so the SKU table can
            // render "Variant N: label" without the frontend chasing extra
            // requests for each value.
            'menuProducts.menuProductSkus.productSku.optionValue1.option',
            'menuProducts.menuProductSkus.productSku.optionValue2.option',
            'menuProducts.menuProductSkus.productSku.optionValue3.option',
            // Per-SKU thumbnail so the shop sku-table can render the variant's
            // own image (with FE-side fallback to product.image_url for simple
            // SKUs that don't carry their own gallery).
            'menuProducts.menuProductSkus.productSku.galleryFirst',
            'menuProducts.product',
            // The management screen renders one thumbnail per product. Keep
            // the full gallery for order-picking consumers, but let the menu
            // detail screen request a compact payload that stays below the
            // Amplify reverse-proxy response limit.
            $compact
                ? 'menuProducts.product.galleryFirst'
                : 'menuProducts.product.gallery',
            // ProductType so ProductResource can expose `product_type_code`
            // (POS uses lowercase 'combo' to render the combo card variant).
            'menuProducts.product.productType',
            'menuProducts.menuSection',
            // Shop-level topping overrides (tier 1) — must be loaded on MenuProduct
            // so MenuProductResource can stamp _shop_topping_overrides without N+1.
            'menuProducts.toppingOverrides',
            // Load master + brand so MenuResource can embed the full timeout chain
            // (hq_brand_timeout_minutes, hq_menu_timeout_minutes, effective_timeout_minutes).
            'masterMenu',
            'masterMenu.brand',
            'branch',
        ]);

        // Plan 015 — surface topping_groups (filtered by shop-local time)
        // alongside the existing product/sku chain.
        $this->service->loadToppingsForMenu($menu);

        // Expose menu_products_count (DISTINCT products) so the detail header
        // reads the SAME authoritative count as the list. Without it the
        // frontend re-derives its own number and the two views disagree when a
        // product is placed in more than one section.
        $menu->loadCount($this->service->menuProductCounts());

        $this->stampFloatingSectionPrices($menu->menuProducts, (string) $menu->branch_id);
        $this->stampEffectiveTaxRates($menu);

        return new MenuResource($menu);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/products',
        summary: 'List products in a branch menu',
        description: 'Returns products in the menu, paginated. Filterable by active status and free-text search that matches against product name, ProductSku.name (variant label), or ProductSku.sku (barcode/SKU code).',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of menu products',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Menu not found'),
        ],
    )]
    /**
     * #3163 — danh sách SECTION của menu, kèm số món mỗi section.
     *
     * Rẻ và LUÔN ĐỦ: chi phí không phụ thuộc số món, nên thanh pill của POS
     * đúng dù menu to cỡ nào. Đây là nửa cho phép lưới thôi tải cả thực đơn.
     */
    public function listSections(Request $request): JsonResponse
    {
        $menu = $this->resolveMenu($request);
        $this->authorize('shopView', $menu);

        return response()->json(['data' => $this->service->listBranchMenuSections($menu)]);
    }

    public function listProducts(Request $request): AnonymousResourceCollection
    {
        $menu = $this->resolveMenu($request);
        $this->authorize('shopView', $menu);

        $products = $this->service->listBranchMenuProducts($menu, [
            'search' => $request->input('search'),
            // #3163 — hai bộ lọc cho phép POS thôi tải cả thực đơn. Chúng phải
            // khai ở ĐÂY chứ không chỉ ở service: `$request->validate()` strip
            // mọi key không có rule, và một tham số đi hết đường service mà
            // không bao giờ nhận được giá trị từ client là lỗi im lặng #2622.
            'section_id' => $request->input('section_id'),
            'sku_id' => $request->input('sku_id'),
            'per_page' => min($request->integer('per_page', 50), 100),
        ]);

        // Plan-019 — batch-overlay active_promotion per product so the
        // POS catalog card can render strikethrough + HH Badge in sync
        // with what the customer sees. resolveActivePromotionsForMenu
        // hits the per-branch Redis cache (TTL 60s) so adding this here
        // doesn't change the asymptotic cost — even on large menus it
        // resolves in O(1) cache hits during a typical shift.
        $branchId = (string) $menu->branch_id;
        $items = [];
        foreach ($products->items() as $mp) {
            if (! $mp->product) {
                continue;
            }
            $items[] = [
                'product_id' => $mp->product_id,
                'category_ids' => $mp->product->relationLoaded('categories')
                    ? $mp->product->categories->pluck('id')->all()
                    : [],
            ];
        }
        $promotionMap = $this->promotionService->resolveActivePromotionsForMenu($branchId, $items);

        $this->stampFloatingSectionPrices(collect($products->items()), $branchId);

        foreach ($products->items() as $mp) {
            $promo = $promotionMap[$mp->product_id] ?? null;
            if ($promo === null) {
                continue;
            }
            // Pin the resolved promo on the model so MenuProductResource
            // can emit the overlay without a second DB hit. Per-SKU
            // discounted_price is recomputed on the FE — the resolver
            // doesn't know which SKU the cashier will pick.
            $mp->setAttribute('active_promotion_overlay', [
                'id' => $promo->id,
                'discount_percent' => (float) $promo->discount_percent,
                'stacking_mode' => $promo->stacking_mode instanceof \BackedEnum
                    ? $promo->stacking_mode->value
                    : (string) $promo->stacking_mode,
                'ends_at' => $this->resolvePromotionEndsAt($promo),
            ]);
        }

        return MenuProductResource::collection($products);
    }

    /** Attach the live promotional price without replacing the editable menu price. */
    /**
     * #1227 — stamp each branch menu line with the tax rate it will actually be
     * billed at, plus the two menu-side tiers above it.
     *
     * The rate comes from `TaxResolver`, the same class the bill uses, walked
     * with this menu + section context. Re-deriving the tier order in the client
     * was the obvious shortcut and the wrong one: the shop screen would drift
     * from the receipt the moment a tier changed, which is exactly the failure
     * `CustomerMenuService` already guards against for the customer menu.
     *
     * The shop cannot EDIT any of this (#1226) — HQ owns tax — so the tiers are
     * surfaced to explain the number, not to offer a control.
     */
    private function stampEffectiveTaxRates(Menu $menu): void
    {
        $sectionTaxTypeIds = $menu->menuSections
            ->mapWithKeys(fn ($section) => [$section->id => $section->pivot->tax_type_id ?? null])
            ->all();

        $rateById = TaxType::query()
            ->whereIn('id', array_values(array_filter(array_merge(
                $sectionTaxTypeIds,
                [$menu->tax_type_id],
            ))))
            ->pluck('rate', 'id')
            ->map(fn ($rate) => (float) $rate)
            ->all();

        $menu->setAttribute(
            'tax_rate',
            $menu->tax_type_id === null ? null : ($rateById[$menu->tax_type_id] ?? null),
        );
        $menu->setAttribute('section_tax_rates', collect($sectionTaxTypeIds)
            ->map(fn ($id) => $id === null ? null : ($rateById[$id] ?? null))
            ->all());

        // One resolver for the whole menu so the branch/brand default lookups
        // are memoised across every line instead of re-queried per product.
        $resolver = new TaxResolver;

        foreach ($menu->menuProducts as $menuProduct) {
            if ($menuProduct->product === null) {
                continue;
            }

            $menuProduct->setAttribute('effective_tax_rate', $resolver->resolveRateForDisplay(
                $menuProduct->product,
                $menuProduct->taxType,
                (string) $menu->branch_id,
                (string) $menu->brand_id,
                $menuProduct->menu_id,
                $menuProduct->menu_section_id,
            ));
        }
    }

    private function stampFloatingSectionPrices($menuProducts, string $branchId): void
    {
        $skus = $menuProducts->flatMap(fn ($product) => $product->menuProductSkus ?? collect());
        $resolved = $this->floatingSectionPrices->resolveForSkus(
            $branchId,
            $skus->pluck('product_sku_id')->all(),
        );

        foreach ($skus as $sku) {
            $floating = $resolved[$sku->product_sku_id] ?? null;
            $effective = $floating === null
                ? (float) $sku->selling_price
                : min((float) $sku->selling_price, $floating['price']);
            $sku->setAttribute('effective_selling_price', $effective);
            $sku->setAttribute('active_floating_section', $floating);
        }
    }

    /**
     * Resolve the next `ends_at` clock for a Happy Hour overlay — uses
     * the promotion's daily_time_to today (or tomorrow if already past)
     * clamped by valid_until. Mirrors CustomerMenuService's helper so the
     * POS card's countdown reads the same value the customer sees.
     */
    private function resolvePromotionEndsAt(MenuPromotion $promo): ?string
    {
        if ($promo->daily_time_to === null) {
            return $promo->valid_until?->toIso8601String();
        }
        try {
            $now = CarbonImmutable::now();
            [$hour, $minute] = array_pad(explode(':', $promo->daily_time_to, 2), 2, '0');
            $todayEnds = $now->setTime((int) $hour, (int) $minute, 0);
            $clamp = $promo->valid_until ? CarbonImmutable::parse($promo->valid_until) : null;
            $candidate = $now->gt($todayEnds) ? $todayEnds->addDay() : $todayEnds;
            if ($clamp !== null && $candidate->gt($clamp)) {
                $candidate = $clamp;
            }

            return $candidate->toIso8601String();
        } catch (\Throwable) {
            return $promo->valid_until?->toIso8601String();
        }
    }

    // =========================================================================
    //  Toggle
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/products/{menuProduct}/toggle',
        summary: 'Toggle a menu product active/inactive',
        description: 'Flips the is_active flag on a menu product for this shop.',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Toggled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function toggleProduct(Request $request): MenuProductResource
    {
        $menu = $this->resolveMenu($request);
        $this->authorize('shopUpdateAvailability', $menu);

        $menuProduct = $this->resolveMenuProduct($menu, $request);

        $toggled = $this->service->toggleProductForShop($menuProduct);

        return new MenuProductResource($toggled);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/sections/{menuSection}/products/bulk-toggle',
        summary: 'Bulk enable/disable every product in one menu section',
        description: 'Sets is_active on ALL menu products of the given section in one call — the "bật tất cả món" button. Body: {"is_active": true|false}.',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuSection', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['is_active'],
                properties: [new OA\Property(property: 'is_active', type: 'boolean')],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Rows flipped', content: new OA\JsonContent(properties: [new OA\Property(property: 'updated', type: 'integer')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function bulkToggleSectionProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $menu = $this->resolveMenu($request);
        $this->authorize('shopUpdateAvailability', $menu);

        // Scope the section to this menu so a foreign section id 404s.
        $sectionId = (string) $request->route('menuSection');
        $menu->menuSections()->findOrFail($sectionId);

        $updated = $this->service->setSectionProductsActiveForShop(
            $menu,
            $sectionId,
            (bool) $validated['is_active'],
        );

        return response()->json(['updated' => $updated]);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/products/{menuProduct}/skus/{menuProductSku}/toggle',
        summary: 'Toggle a menu product SKU active/inactive',
        description: 'Flips the is_active flag on a specific SKU within a menu product.',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProductSku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Toggled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function toggleSku(Request $request): MenuProductSkuResource
    {
        $menu = $this->resolveMenu($request);
        $this->authorize('shopUpdateAvailability', $menu);

        $menuProduct = $this->resolveMenuProduct($menu, $request);
        $sku = $this->resolveMenuProductSku($menuProduct, $request);

        $toggled = $this->service->toggleSkuForShop($sku);

        return new MenuProductSkuResource($toggled);
    }

    // =========================================================================
    //  Price override
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/products/{menuProduct}/skus/{menuProductSku}/price',
        summary: 'Override the per-shop selling price of a SKU',
        description: 'Restricted to Shop Manager and above. Sets is_price_overridden=true.',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProductSku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['selling_price'],
                properties: [
                    new OA\Property(property: 'selling_price', type: 'number', format: 'float', minimum: 0),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Price overridden', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function overrideSkuPrice(ShopOverrideSkuPriceRequest $request): MenuProductSkuResource
    {
        $menu = $this->resolveMenu($request);
        $this->authorize('shopUpdatePrice', $menu);

        $menuProduct = $this->resolveMenuProduct($menu, $request);
        $sku = $this->resolveMenuProductSku($menuProduct, $request);

        $updated = $this->service->overrideSkuPrice($sku, (float) $request->validated('selling_price'));

        return new MenuProductSkuResource($updated);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/products/{menuProduct}/skus/{menuProductSku}/reset-price',
        summary: 'Reset a SKU selling price back to default',
        description: 'Restricted to Shop Manager and above. Resets to the canonical productSku.selling_price.',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProduct', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'menuProductSku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Price reset', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function resetSkuPrice(Request $request): MenuProductSkuResource
    {
        $menu = $this->resolveMenu($request);
        $this->authorize('shopUpdatePrice', $menu);

        $menuProduct = $this->resolveMenuProduct($menu, $request);
        $sku = $this->resolveMenuProductSku($menuProduct, $request);

        $updated = $this->service->resetSkuPrice($sku);

        return new MenuProductSkuResource($updated);
    }

    // =========================================================================
    //  Sync
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/menus/{menu}/sync',
        summary: 'Sync new products from master menu',
        description: 'Adds any new products from the master menu into this branch menu.',
        tags: ['Shop Menus'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'menu', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Synced', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Menu')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Menu not found'),
            new OA\Response(response: 422, description: 'Menu is not cloned from a master'),
        ],
    )]
    public function syncFromMaster(Request $request): MenuResource
    {
        $menu = $this->resolveMenu($request);
        $this->authorize('shopUpdateAvailability', $menu);

        $menu = $this->service->syncFromMaster($menu);

        return new MenuResource($menu);
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
     * Resolve {menu} from the route, scoped to the resolved shop's branch
     * AND the user's organization.
     */
    private function resolveMenu(Request $request): Menu
    {
        $shop = $this->resolvedShop($request);
        $id = (string) $request->route('menu');

        return Menu::query()
            ->where('organization_id', $this->getOrganizationId())
            ->where('branch_id', $shop->id)
            ->findOrFail($id);
    }

    /**
     * Resolve {menuProduct} from the route, scoped to the parent menu.
     */
    private function resolveMenuProduct(Menu $menu, Request $request): MenuProduct
    {
        $id = (string) $request->route('menuProduct');

        return $menu->menuProducts()->findOrFail($id);
    }

    /**
     * Resolve {menuProductSku} from the route, scoped to the parent menu product.
     */
    private function resolveMenuProductSku(MenuProduct $menuProduct, Request $request): MenuProductSku
    {
        $id = (string) $request->route('menuProductSku');

        return $menuProduct->menuProductSkus()->findOrFail($id);
    }
}
