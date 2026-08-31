<?php

namespace App\Services\Customer;

use App\Models\Branch;
use App\Models\FloatingSection;
use App\Models\Menu;
use App\Models\ToppingGroupItem;
use App\Omnify\Enums\MenuScheduleRecurrenceEnum;
use App\Omnify\Enums\MenuStatusEnum;
use App\Omnify\Enums\ProductStatusEnum;
use App\Services\Product\Contracts\FloatingSectionAvailability;
use App\Services\Promotion\Contracts\FloatingSectionPricing;
use App\Services\Promotion\Contracts\MenuDisplayPromotion;
use App\Services\Promotion\Contracts\MenuDisplayPromotions;
use App\Services\Tax\Contracts\MenuDisplayTaxRateBatch;
use App\Services\Tax\Contracts\MenuDisplayTaxRates;
use App\Services\Topping\Contracts\ToppingLinePricing;
use App\Support\BusinessClock;
use App\Support\MenuScheduleDateRule;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CustomerMenuService
{
    /** Memoised per menu build (#1099) — see resolveItemTaxRate(). */
    private ?MenuDisplayTaxRateBatch $menuTaxRates = null;

    public function __construct(
        // #962 — hợp đồng công bố của Pricing thay cho service cụ thể + model
        // `MenuPromotion`. Cửa sổ "khuyến mãi tắt lúc nào" cũng về theo, xem
        // `MenuDisplayPromotions`.
        private readonly MenuDisplayPromotions $promotions,
        // #1597 — hợp đồng, không phải lớp cụ thể. Bỏ luôn giá trị mặc định
        // `new …`: nó là thứ buộc tham số phải là lớp cụ thể, và không chỗ nào
        // dựng service này bằng `new` (đã grep) — container luôn giải.
        private readonly FloatingSectionPricing $floatingSectionPrices,
        // #1622 — "section nào đang phát sóng" là câu hỏi của Catalog, tách khỏi
        // "giá nào thắng" ở trên. Trước đây service này tự trả lời cả hai.
        private readonly FloatingSectionAvailability $floatingSectionAvailability,
        private readonly MenuLocalizationIntegrityReporter $localizationIntegrity,
        private readonly ToppingLinePricing $toppingPricing,
        // #1596 — hợp đồng công bố của Pricing thay cho bộ giải thuế dựng tại
        // chỗ (`App\Services\Customer\TaxResolver`, layer Pricing). Cổng
        // hiển thị riêng, KHÔNG dùng chung với đường ghi đơn: xem
        // `MenuDisplayTaxRates` về cảnh báo thu-thiếu-thuế mà đường này cố ý im.
        private readonly MenuDisplayTaxRates $displayTaxRates,
    ) {}

    /**
     * Load the active menu for a branch and transform into frontend shape.
     *
     * Filters menus by schedule: only returns menus that:
     * - Have no schedules (always available), OR
     * - Have at least one active schedule matching current day-of-week + time
     *
     * Frontend expects: categories[] → items[] → options[] → variants[]
     *
     * @return array{categories: array<int, array{id: string, name: string, items: array}>}|null
     */
    public function getMenuForBranch(string $branchId, ?string $brandId = null, ?string $serviceType = null): ?array
    {
        // Resolve timezone: prefer branch (shop) timezone; fallback to HQ/app timezone.
        $branch = Branch::find($branchId);
        $timezone = $this->resolveBranchTimezone($branch);

        $now = Carbon::now($timezone);
        $dayOfWeek = $now->dayOfWeek; // 0=Sun, 1=Mon, ..., 6=Sat
        $currentTime = $now->format('H:i:s');
        $currentDate = $now->toDateString();

        $query = Menu::where('branch_id', $branchId)
            ->where('status', MenuStatusEnum::Active->value)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
            ->where(function ($q) use ($dayOfWeek, $currentTime, $currentDate, $branchId) {
                // Either: menu has no schedules (always available)
                $q->whereDoesntHave('schedules')
                    // OR: has at least one active schedule window for this branch
                    ->orWhereHas('activeSchedules', function ($scheduleQuery) use ($dayOfWeek, $currentTime, $currentDate, $branchId) {
                        $scheduleQuery
                            // Calendar window. Since #1970 the POS/workstation
                            // surfaces enforce it too (reversing the #1237
                            // asymmetry, where staff could still sell a campaign
                            // menu the guest could no longer see), and the bound
                            // is branch-aware. NULL means unbounded (no DB default).
                            ->tap(fn ($rule) => MenuScheduleDateRule::apply($rule, $branchId, $dayOfWeek, $currentDate))
                            // Check time range, preferring branch-specific overrides when present.
                            ->where(function ($scheduleTimeQuery) use ($currentTime, $branchId) {
                                $scheduleTimeQuery
                                    // Case 1: no override for this branch → use base start/end_time
                                    ->where(function ($baseQuery) use ($currentTime, $branchId) {
                                        $baseQuery
                                            ->whereDoesntHave('branchScheduleOverrides', function ($overrideQuery) use ($branchId) {
                                                $overrideQuery->where('branch_id', $branchId);
                                            })
                                            ->where('start_time', '<=', $currentTime)
                                            ->where('end_time', '>=', $currentTime);
                                    })
                                    // Case 2: has override for this branch → use override start/end_time
                                    ->orWhereHas('branchScheduleOverrides', function ($overrideQuery) use ($branchId, $currentTime) {
                                        $overrideQuery
                                            ->where('branch_id', $branchId)
                                            ->whereRaw('(start_time IS NULL OR start_time <= ?)', [$currentTime])
                                            ->whereRaw('(end_time IS NULL OR end_time >= ?)', [$currentTime]);
                                    });
                            });
                    });
            });

        if ($brandId) {
            $query->where('brand_id', $brandId);
        }

        // Service-type gating (#463): a menu marked Takeaway only shows in the
        // takeaway flow, DineIn only in the dine-in flow. "Both" (the default,
        // and every legacy menu) shows in both. A branch menu with NULL
        // service_type inherits its master (HQ) menu's type live; NULL with no
        // master falls back to Both (always shows). When the caller doesn't
        // specify a service type, no filter is applied (back-compat).
        if ($serviceType !== null) {
            $wanted = [$serviceType, 'Both'];
            $query->where(function ($q) use ($wanted) {
                // Own value gates directly …
                $q->whereIn('service_type', $wanted)
                    // … or NULL = inherit the master menu's type …
                    ->orWhere(function ($inherit) use ($wanted) {
                        $inherit->whereNull('service_type')
                            ->where(function ($resolve) use ($wanted) {
                                $resolve->whereHas('masterMenu', fn ($mq) => $mq->whereIn('service_type', $wanted))
                                    // … or NULL with no master → fall back to Both.
                                    ->orWhereDoesntHave('masterMenu');
                            });
                    });
            });
        }

        $menu = $query->with([
            'translations',
            'menuProducts' => fn ($q) => $q
                ->where('is_active', true)
                // #902 — only surface products the customer can actually order.
                // A MenuProduct can point at a since-paused / never-activated
                // product; without this the menu would show items the order
                // gate (CustomerOrderService::addItems) rejects with 422.
                ->whereHas('product', fn ($p) => $p->where('status', ProductStatusEnum::Active->value))
                ->orderBy('display_order')
                // #3170 — `display_order` is not a total order (104/127 rows of a
                // real menu sit on 0), so without a tie-break this path is free
                // to order the tied block differently from MenuService /
                // MenuCatalogReplicaBuilder. Same unique UUIDv7 tie-break the
                // paginated listing took in #3160.
                ->orderBy('menu_products.id'),
            'menuProducts.menuSection.translations',
            'menuProducts.product.translations',
            'menuProducts.product.productType',
            // plan-043 T5.4 — the item's consumption-tax rate, so customer-web
            // can render 総額表示 tax-included prices. A tax type is ONE rate
            // (#1099): the menu line decides the consumption context, so a
            // takeaway menu carries the reduced type as a tier-1 override here.
            //
            // #1596 — the two `taxType` RELATIONS used to be eager-loaded here
            // purely to hand models to the resolver. The display port takes ids
            // (`tax_type_id`, already on the row) and rehydrates them once per
            // distinct id inside the batch memo, so loading them again would be
            // two queries nothing reads. Every other tier was already memoised.
            'masterMenu', // For cart timeout cascade
            'masterMenu.brand', // For cart timeout cascade
            // #1185 — the pivot carries `display_order`, and Menu::menuSections
            // already orders by it, so loading the relation is enough to sort
            // the customer view the way the curator arranged it.
            'menuSections',
            // Plan-019 — categories needed by MenuDisplayPromotions::forMenuItems
            // when a promotion's applies_to is `categories` or `mixed`.
            'menuProducts.product.categories:id',
            // galleryFirst = lightest MorphOne to the first gallery image.
            // Matches ProductResource::resolveImageUrl() so the customer
            // menu list surfaces the same thumbnail as admin lists.
            'menuProducts.product.galleryFirst',
            // Full gallery (ordered by sort_order) so the customer menu card
            // can render every product photo, not just the first thumbnail.
            'menuProducts.product.gallery',
            'menuProducts.menuProductSkus' => fn ($q) => $q
                ->where('is_active', true),
            'menuProducts.menuProductSkus.productSku.translations',
            // Per-variant thumbnail so the frontend can swap the
            // modal hero image when the user picks a variant.
            'menuProducts.menuProductSkus.productSku.galleryFirst',
            'menuProducts.product.options' => fn ($q) => $q
                ->where('is_active', true)
                ->orderBy('position'),
            'menuProducts.product.options.translations',
            'menuProducts.product.options.values' => fn ($q) => $q
                ->where('is_active', true)
                ->orderBy('position'),
            'menuProducts.product.options.values.translations',
            // Topping groups — eager load with items, pricing, and product info
            'menuProducts.product.toppingGroups' => fn ($q) => $q
                ->where('topping_groups.is_active', true)
                // #2109 — `product_topping_groups.sort_order` is NOT unique and
                // real data carries ties, so on a tie MySQL falls back to physical
                // row order and the customer sees a different GROUP order than the
                // admin arranged. The pivot's auto-increment `id` breaks the tie
                // deterministically (and in insertion order).
                ->orderBy('product_topping_groups.sort_order')
                ->orderBy('product_topping_groups.id'),
            'menuProducts.product.toppingGroups.translations',
            // `sort_order` is NOT unique — nothing in the schema or the write
            // path enforces it, and production data already carries ties (#2046).
            // On a tie MySQL is free to return rows in physical order, so the
            // customer saw a different topping order than the admin dragged.
            // `id` is UUIDv7 (time-sortable AND unique), so it breaks the tie
            // deterministically without changing the intended ordering.
            'menuProducts.product.toppingGroups.items' => fn ($q) => $q
                ->whereHas('product', fn ($productQuery) => $productQuery
                    ->where('status', ProductStatusEnum::Active->value))
                ->orderBy('sort_order')
                ->orderBy('id'),
            // Only orderable topping SKUs: a scoped row must point at an ACTIVE
            // ProductSku, but the wildcard simple-topping row (product_sku_id
            // NULL) carries the price for non-variant toppings and must survive
            // — see resolveSimpleToppingSkuId(). Without this filter the menu
            // shows (and defaults to) an inactive variant's stale price while
            // the order gate would reject / re-price it (#combo-inactive-variant).
            'menuProducts.product.toppingGroups.items.skus' => fn ($q) => $q
                ->where(fn ($sku) => $sku
                    ->whereNull('product_sku_id')
                    ->orWhereHas('productSku', fn ($ps) => $ps->where('is_active', true))),
            'menuProducts.product.toppingGroups.items.skus.productSku.translations',
            // Option values so variant_label can compose "Ít / Nongs" (the SKU's
            // own name column is null for option-based variants).
            'menuProducts.product.toppingGroups.items.skus.productSku.optionValue1',
            'menuProducts.product.toppingGroups.items.skus.productSku.optionValue2',
            'menuProducts.product.toppingGroups.items.skus.productSku.optionValue3',
            // Shop (tier 1) + HQ (tier 2) topping overrides so the customer menu
            // hides a topping the shop/HQ marked is_hidden — the order gate
            // rejects a hidden topping, so the menu must not offer it.
            'menuProducts.toppingOverrides',
            'menuProducts.product.toppingGroups.items.productOverrides',
            'menuProducts.product.toppingGroups.items.product.translations',
            // Simple (non-variant) topping items carry a wildcard
            // topping_group_item_skus row with product_sku_id = NULL, so the
            // orderable SKU has to come from the topping product itself —
            // see resolveSimpleToppingSkuId().
            'menuProducts.product.toppingGroups.items.product.skus' => fn ($q) => $q
                ->where('is_active', true),
            'menuProducts.product.toppingGroups.items.product.galleryFirst',
            // Load options for topping item products so we can render "Phở Bò (Regular)" vs "Phở Bò (Large)"
            'menuProducts.product.toppingGroups.items.product.options' => fn ($q) => $q
                ->where('is_active', true)
                ->orderBy('position'),
            'menuProducts.product.toppingGroups.items.product.options.values' => fn ($q) => $q
                ->where('is_active', true)
                ->orderBy('position'),
            'activeSchedules' => fn ($q) => $q
                ->tap(fn ($rule) => MenuScheduleDateRule::apply($rule, $branchId, $dayOfWeek, $currentDate))
                ->where(function ($scheduleTimeQuery) use ($currentTime, $branchId) {
                    $scheduleTimeQuery
                        // Case 1: no override for this branch → use base schedule times
                        ->where(function ($baseQuery) use ($currentTime, $branchId) {
                            $baseQuery
                                ->whereDoesntHave('branchScheduleOverrides', function ($overrideQuery) use ($branchId) {
                                    $overrideQuery->where('branch_id', $branchId);
                                })
                                ->where('start_time', '<=', $currentTime)
                                ->where('end_time', '>=', $currentTime);
                        })
                        // Case 2: has override for this branch → use override times
                        ->orWhereHas('branchScheduleOverrides', function ($overrideQuery) use ($branchId, $currentTime) {
                            $overrideQuery
                                ->where('branch_id', $branchId)
                                ->whereRaw('(start_time IS NULL OR start_time <= ?)', [$currentTime])
                                ->whereRaw('(end_time IS NULL OR end_time >= ?)', [$currentTime]);
                        });
                })
                ->orderBy('priority'),
        ])
            // Lower priority number = higher priority (matches the schema
            // contract + menu reorder, which assigns 1-based priority top-down).
            // Was orderByDesc — a bug that picked the LOWEST-priority menu.
            ->orderBy('priority')
            // Was ->first() — now every active menu in this window is merged
            // into one view (each menu becomes its own group of sections),
            // ordered by priority. See mergeMenus().
            ->get();

        if ($menu->isEmpty()) {
            return null;
        }

        // Resolve the effective floating-section price ONCE for the whole
        // branch, then stamp it on every menu's SKU models (union across all
        // merged menus). The resolver keys by product_sku_id + branch, so a
        // single call covers every menu; stamping in-place means all downstream
        // transforms (default price, variants, option deltas, the floating
        // section category) read the same value.
        $allMenuSkus = $menu->flatMap(fn (Menu $m) => $m->menuProducts->flatMap->menuProductSkus);
        $floatingPrices = $this->floatingSectionPrices->resolveForSkus(
            $branchId,
            $allMenuSkus->pluck('product_sku_id')->filter()->all(),
        );
        foreach ($allMenuSkus as $menuSku) {
            $floating = $floatingPrices[$menuSku->product_sku_id] ?? null;
            if ($floating === null || $floating['price'] >= (float) $menuSku->selling_price) {
                continue;
            }
            $menuSku->setAttribute('base_selling_price', (float) $menuSku->selling_price);
            $menuSku->setAttribute('selling_price', $floating['price']);
            $menuSku->setAttribute('active_floating_section', $floating);
        }

        // Transform each menu independently (keeps its own schedule window +
        // cart deadline correct — #478), then merge into one payload.
        $transformed = $menu->map(function (Menu $m) use ($branch, $timezone) {
            $this->localizationIntegrity->inspect($m, app()->getLocale());

            // Plan-019 — per-product active_promotion, one batch call per menu.
            $items = [];
            foreach ($m->menuProducts as $menuProduct) {
                $product = $menuProduct->product;
                if (! $product) {
                    continue;
                }
                $items[] = [
                    'product_id' => $product->id,
                    'category_ids' => $product->categories->pluck('id')->all(),
                ];
            }
            $promotionMap = $this->promotions->forMenuItems((string) $m->branch_id, $items);

            return $this->transformMenu($m, $promotionMap, $branch, $timezone);
        });

        return $this->mergeMenus($transformed, $branchId, $timezone);
    }

    /**
     * Merge N per-menu transformed payloads into one customer view.
     *
     * - Top-level menu_id/menu_name/schedule/deadline come from the FIRST
     *   (highest-priority) menu for backward compatibility with clients that
     *   read them globally. Under a merge those fields describe ONE menu, not
     *   the view — `menus` below is the honest list (#1702).
     * - Every item carries its OWN menu_id/menu_name/menu_end_time/item_deadline
     *   (from the menu it belongs to) so per-item cart enrichment (#478) stays
     *   correct across merged menus.
     * - The floating-section category (khung giờ ưu đãi) is prepended as its own
     *   section, sorted internally by FloatingSection.priority.
     *
     * @param  Collection<int, array<string, mixed>>  $transformed
     * @return array<string, mixed>
     */
    private function mergeMenus($transformed, string $branchId, string $timezone): array
    {
        $head = $transformed->first();

        $categories = [];
        // #1702 — sku_id ⇒ menu sở hữu nó. Menu ĐẦU TIÊN (priority cao nhất) có
        // SKU đó thắng, để item spotlight bên dưới mượn được ngữ cảnh của một
        // menu THẬT SỰ chứa nó thay vì của menu head.
        $menuBySku = [];
        foreach ($transformed as $menuPayload) {
            foreach ($menuPayload['categories'] as $category) {
                // Stamp the owning menu's context onto every item so the client
                // enriches the cart from the RIGHT menu (schedule/deadline).
                $category['items'] = array_map(function (array $item) use ($menuPayload, &$menuBySku) {
                    $item['menu_id'] = $menuPayload['menu_id'];
                    $item['menu_name'] = $menuPayload['menu_name'];
                    $item['menu_end_time'] = $menuPayload['schedule_end_time'];
                    $item['item_deadline'] = $menuPayload['cart_deadline_iso'];

                    $skuId = $item['sku_id'] ?? null;
                    if (is_string($skuId) && ! array_key_exists($skuId, $menuBySku)) {
                        $menuBySku[$skuId] = $menuPayload;
                    }

                    return $item;
                }, $category['items']);
                $categories[] = $category;
            }
        }

        // Floating section (khung giờ ưu đãi) as its own section, ON TOP.
        // Built INDEPENDENTLY from the floating_section_* tables — it lists EVERY
        // product the admin put in an active floating section, even ones not in
        // any menu (that is the whole point of a spotlight). Each floating
        // section becomes one section; multiple sections stack by priority.
        $floatingCategories = $this->buildFloatingSectionCategories($branchId, $head, $timezone, $menuBySku);
        // Prepend in reverse so the lowest-priority-number section ends up first.
        foreach (array_reverse($floatingCategories) as $fsCategory) {
            array_unshift($categories, $fsCategory);
        }

        return [
            'menu_id' => $head['menu_id'],
            'menu_name' => $head['menu_name'],
            'schedule_start_time' => $head['schedule_start_time'],
            'schedule_end_time' => $head['schedule_end_time'],
            'cart_timeout_minutes' => $head['cart_timeout_minutes'],
            'cart_deadline_iso' => $head['cart_deadline_iso'],
            'review_avg_rating' => $head['review_avg_rating'],
            'review_total_count' => $head['review_total_count'],
            // #1702 — mọi menu được gộp vào view này, theo thứ tự priority. Client
            // cần nó để biết menu của một món trong giỏ còn mở hay đã đóng: trước
            // đó nó chỉ có ngữ cảnh của head và phải suy đoán, nên coi mọi món của
            // menu thứ 2 trở đi là "thuộc menu cũ".
            'menus' => $transformed->map(fn (array $menuPayload) => [
                'menu_id' => $menuPayload['menu_id'],
                'menu_name' => $menuPayload['menu_name'],
                'schedule_start_time' => $menuPayload['schedule_start_time'],
                'schedule_end_time' => $menuPayload['schedule_end_time'],
                'cart_timeout_minutes' => $menuPayload['cart_timeout_minutes'],
                'cart_deadline_iso' => $menuPayload['cart_deadline_iso'],
            ])->values()->all(),
            'categories' => $categories,
        ];
    }

    /**
     * Build one spotlight category per ACTIVE floating section for the branch,
     * listing every product the admin placed in it — independent of any menu.
     *
     * Each item is built to the SAME shape transformMenu emits (price, tax,
     * options, toppingGroups, images…) so customer-web renders + orders it
     * exactly like a normal menu item. Off-menu items order through the order
     * engine's product-anchored (#514) path — the client sends product_sku_id
     * only, so no menu_product_sku_id is needed.
     *
     * @param  array<string, mixed>  $head  The head menu payload (fallback for off-menu spotlight products).
     * @param  array<string, array<string, mixed>>  $menuBySku  sku_id ⇒ menu payload sở hữu SKU đó (#1702).
     * @return array<int, array<string, mixed>> Ordered by FloatingSection.priority (lower = higher).
     */
    private function buildFloatingSectionCategories(string $branchId, array $head, string $timezone, array $menuBySku = []): array
    {
        $sections = $this->activeFloatingSectionsForBranch($branchId, $timezone);
        if ($sections->isEmpty()) {
            return [];
        }

        $brand = Branch::find($branchId)?->brand ?? null;
        $brandId = $sections->first()->brand_id;

        $categories = [];
        foreach ($sections as $section) {
            $items = [];
            foreach ($section->products as $sectionProduct) {
                if (! $sectionProduct->is_active) {
                    continue;
                }
                $product = $sectionProduct->product;
                // status is cast to ProductStatusEnum on the model — compare the
                // enum, not a raw string.
                $status = $product?->status;
                $statusValue = $status instanceof ProductStatusEnum ? $status->value : $status;
                if (! $product || $statusValue !== ProductStatusEnum::Active->value) {
                    continue;
                }

                // Sellable floating SKUs only — an inactive SKU (or one whose
                // catalog ProductSku is inactive) can't be ordered.
                $skus = $sectionProduct->skus->filter(
                    fn ($s) => $s->is_active && $s->productSku && $s->productSku->is_active
                )->values();
                if ($skus->isEmpty()) {
                    continue;
                }

                $defaultSku = $skus->first();
                $price = (float) $defaultSku->selling_price;

                // #1702 — ngữ cảnh menu cho item spotlight lấy từ menu THẬT SỰ
                // chứa SKU này. Trước đó luôn lấy của menu head, nên một món
                // spotlight của menu お持ち帰り (đóng 21:45) nhận hạn giỏ của menu
                // 人形町店 (22:15): khách thêm vào giỏ rồi vẫn thấy đặt được sau
                // khi menu của nó đã đóng. Món spotlight KHÔNG nằm ở menu nào —
                // đúng mục đích của spotlight — thì mới rơi về head.
                $owningMenu = $menuBySku[(string) ($defaultSku->productSku?->id ?? '')] ?? $head;

                // #1185 — the pre-discount figure the card strikes through.
                // The spotlight is built off the floating_section_* tables, which
                // only know the PROMO price, so the original has to come from the
                // catalog SKU. Null when it is not actually higher, so a section
                // that "discounts" to the normal price renders no strikethrough.
                $catalogPrice = (float) ($defaultSku->productSku?->selling_price ?? 0);
                $basePrice = $catalogPrice > $price ? $catalogPrice : null;

                // Options/variants: transformOptions matches option values to the
                // floating SKUs (same field shape as menu SKUs — productSku +
                // selling_price), so a multi-variant floating product still lists
                // its sizes. Price is a delta from the default floating price.
                $options = $this->transformOptions($product, $skus, $price);

                // Topping — pass the floating product's own tier-1 overrides so
                // the displayed (and, via resolveToppingPrice → resolver param 6,
                // the charged) topping price matches the admin's shop overrides.
                $toppingGroups = $this->transformToppingGroups(
                    $product,
                    null,
                    $sectionProduct->relationLoaded('toppingOverrides') ? $sectionProduct->toppingOverrides : null,
                    (string) $sectionProduct->id,
                );

                $image = $defaultSku->productSku?->galleryFirst?->getUrl()
                    ?? $product->galleryFirst?->getUrl();

                $items[] = [
                    // Synthetic-but-stable per-item id (floating product id).
                    'id' => $sectionProduct->id,
                    'sku_id' => $defaultSku->productSku?->id,
                    // Off-menu: the client orders by product_sku_id only.
                    'menu_product_sku_id' => null,
                    'name' => $product->name,
                    'description' => $product->description ? strip_tags($product->description) : null,
                    'price' => $price,
                    'base_price' => $basePrice,
                    'active_floating_section' => [
                        'floating_section_id' => $section->id,
                        'name' => $section->name,
                        'price' => $price,
                        'priority' => (int) $section->priority,
                    ],
                    // Floating-section tax override (parity with MenuProduct):
                    // fsp.taxType wins over the product's own type, else inherit.
                    'tax_type_id' => $sectionProduct->tax_type_id ?? $product->tax_type_id,
                    'tax_rate' => $this->resolveItemTaxRate($sectionProduct, $product, $branchId, (string) ($brandId ?? $product->brand_id)),
                    'image' => $image,
                    'images' => $product->gallery->map(fn ($file) => $file->getUrl())->values()->all(),
                    'status' => 'available',
                    'options' => $options ?: null,
                    'toppingGroups' => $toppingGroups ?: null,
                    'is_combo' => $product->productType?->code === 'combo',
                    'active_promotion' => null,
                    'rating' => $product->recommendPercent(),
                    'reviewCount' => $product->review_total_count,
                    // Per-item menu context — lịch/hạn giỏ của menu sở hữu SKU này
                    // (#1702), head chỉ là fallback cho món off-menu. `menu_name`
                    // vẫn là tên section: đó là cái khách nhìn thấy trên card.
                    'menu_id' => $owningMenu['menu_id'] ?? null,
                    'menu_name' => $section->name,
                    'menu_end_time' => $owningMenu['schedule_end_time'] ?? null,
                    'item_deadline' => $owningMenu['cart_deadline_iso'] ?? null,
                ];
            }

            if ($items === []) {
                continue;
            }

            $categories[] = [
                'id' => 'floating-section-'.$section->id,
                'name' => $section->name,
                'is_floating_section' => true,
                'items' => $items,
            ];
        }

        return $categories;
    }

    /**
     * All floating sections currently active for the branch (is_active + the
     * schedule window matches now), with everything needed to build customer
     * items eager-loaded. Ordered by priority (lower = higher).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, FloatingSection>
     */
    private function activeFloatingSectionsForBranch(string $branchId, string $timezone): \Illuminate\Database\Eloquent\Collection
    {
        // #1622 — vị ngữ "đang phát sóng" (khoảng ngày · mặt nạ thứ · HAI nhánh
        // qua nửa đêm) từng được chép nguyên ở đây, kèm một comment tự khai là
        // bản sao của FloatingSectionPriceResolver. Hai bản sao của cùng một luật
        // liên quan tới tiền: chúng lệch nhau thì khách thấy một giá còn đơn hàng
        // tính một giá khác. Giờ Catalog trả lời câu đó, một lần.
        $liveIds = $this->floatingSectionAvailability->liveSectionIds(
            $branchId,
            CarbonImmutable::now($timezone),
        );

        if ($liveIds === []) {
            // Dựng thẳng collection rỗng thay vì `FloatingSection::query()->whereRaw('1 = 0')`:
            // cái sau tốn một round-trip DB chắc chắn không trả gì, VÀ thêm một
            // lần service này chạm model của Catalog — đúng khoản nợ #1596 đang
            // đếm. Không được trả nợ ở đây thì ít nhất đừng cộng thêm.
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return FloatingSection::query()
            ->whereIn('id', $liveIds)
            ->with([
                // #3170 — tie-break on the unique id: `display_order` alone is
                // a partial order, so tied rows may come back in a different
                // sequence than FloatingSectionService hands the HQ screen.
                'products' => fn ($q) => $q->where('is_active', true)
                    ->orderBy('display_order')
                    ->orderBy('floating_section_products.id'),
                // #1596 — `products.taxType` / `products.product.taxType` dropped
                // for the same reason as on the head menu: the display port takes
                // `tax_type_id` and rehydrates once per distinct id.
                'products.product.translations',
                'products.product.productType',
                'products.product.gallery',
                'products.product.galleryFirst',
                'products.product.options' => fn ($q) => $q->where('is_active', true)->orderBy('position'),
                'products.product.options.translations',
                'products.product.options.values' => fn ($q) => $q->where('is_active', true)->orderBy('position'),
                'products.product.options.values.translations',
                // Tie-break on the pivot's unique auto-increment id (#2109) —
                // same rule as the menu path above.
                'products.product.toppingGroups' => fn ($q) => $q->where('topping_groups.is_active', true)->orderBy('product_topping_groups.sort_order')->orderBy('product_topping_groups.id'),
                'products.product.toppingGroups.translations',
                // Tie-break on the unique UUIDv7 id — `sort_order` has ties in
                // real data and would otherwise fall back to physical row order
                // (#2046). Same rule as the menu path above.
                'products.product.toppingGroups.items' => fn ($q) => $q->whereHas('product', fn ($p) => $p->where('status', ProductStatusEnum::Active->value))->orderBy('sort_order')->orderBy('id'),
                'products.product.toppingGroups.items.product.translations',
                'products.product.toppingGroups.items.product.galleryFirst',
                'products.product.toppingGroups.items.product.options' => fn ($q) => $q->where('is_active', true)->orderBy('position'),
                'products.product.toppingGroups.items.product.options.values' => fn ($q) => $q->where('is_active', true)->orderBy('position'),
                'products.product.toppingGroups.items.skus' => fn ($q) => $q->where(fn ($sku) => $sku->whereNull('product_sku_id')->orWhereHas('productSku', fn ($ps) => $ps->where('is_active', true))),
                'products.product.toppingGroups.items.skus.productSku.translations',
                'products.product.toppingGroups.items.skus.productSku.optionValue1',
                'products.product.toppingGroups.items.skus.productSku.optionValue2',
                'products.product.toppingGroups.items.skus.productSku.optionValue3',
                'products.product.toppingGroups.items.productOverrides',
                'products.skus' => fn ($q) => $q->where('is_active', true),
                'products.skus.productSku.translations',
                'products.skus.productSku.galleryFirst',
                'products.skus.productSku.optionValue1',
                'products.skus.productSku.optionValue2',
                'products.skus.productSku.optionValue3',
                'products.toppingOverrides',
            ])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * Describe the next time a configured menu can accept orders.
     *
     * This is used when no menu is active right now, so customer clients can
     * distinguish normal out-of-hours availability from a technical failure.
     *
     * @return array{branch_name: string, menu_name: string, timezone: string, next_opens_at: string, next_closes_at: string|null}|null
     */
    public function getNextOpeningForBranch(string $branchId, ?string $brandId = null, ?string $serviceType = null): ?array
    {
        $branch = Branch::find($branchId);
        if (! $branch) {
            return null;
        }

        $timezone = $this->resolveBranchTimezone($branch);
        $now = CarbonImmutable::now($timezone);

        $query = Menu::query()
            ->where('branch_id', $branchId)
            ->where('status', MenuStatusEnum::Active->value);

        if ($brandId) {
            $query->where('brand_id', $brandId);
        }

        $this->applyServiceTypeGate($query, $serviceType);

        $menus = $query->with([
            'activeSchedules' => fn ($scheduleQuery) => $scheduleQuery
                ->with([
                    'branchScheduleOverrides' => fn ($overrideQuery) => $overrideQuery->where('branch_id', $branchId),
                    // Needed for recurrence_kind = SpecificDates (#1979); empty for the other kinds.
                    'scheduleDates',
                ])
                ->orderBy('priority'),
        ])->get();

        $nextOpening = null;

        foreach ($menus as $menu) {
            foreach ($menu->activeSchedules as $schedule) {
                $override = $schedule->branchScheduleOverrides->first();
                $daysOfWeek = $override?->days_of_week ?? $schedule->days_of_week;
                $startTime = $override?->start_time ?? $schedule->start_time;
                $endTime = $override?->end_time ?? $schedule->end_time;
                // Calendar window follows the same shop-over-HQ rule (#1970) —
                // without this the "opens next at" banner would advertise a
                // reopening the shop's own date override has already ruled out.
                $startDate = $override?->start_date ?? $schedule->start_date;
                $endDate = $override?->end_date ?? $schedule->end_date;

                if (! $startTime) {
                    continue;
                }

                // getRawOriginal: the column is cast to a backed enum, and the
                // match below compares against ->value strings.
                $kind = (string) ($schedule->getRawOriginal('recurrence_kind') ?: MenuScheduleRecurrenceEnum::Weekly->value);
                $daysOfMonth = (int) ($override?->days_of_month ?? $schedule->days_of_month ?? 0);
                // Set of 'Y-m-d' the row names explicitly. Only meaningful when
                // the kind is SpecificDates; inert otherwise (BR-MSD03).
                $specificDates = $schedule->scheduleDates
                    ->map(fn ($row) => substr((string) $row->getRawOriginal('date'), 0, 10))
                    ->all();

                if ($kind === MenuScheduleRecurrenceEnum::Weekly->value && ! $daysOfWeek) {
                    continue;
                }

                // 62 days, not 8. Eight covered every bit of a weekly mask, but
                // "the 1st of the month" can be thirty days out and a named date
                // further still — at eight days the banner would simply claim the
                // shop never reopens, which is worse than saying nothing.
                for ($dayOffset = 0; $dayOffset <= 62; $dayOffset++) {
                    $date = $now->startOfDay()->addDays($dayOffset);

                    $covers = match ($kind) {
                        MenuScheduleRecurrenceEnum::Monthly->value => ($daysOfMonth & (1 << ($date->day - 1))) !== 0,
                        MenuScheduleRecurrenceEnum::SpecificDates->value => in_array($date->toDateString(), $specificDates, true),
                        default => ((int) $daysOfWeek & (1 << $date->dayOfWeek)) !== 0,
                    };

                    if (! $covers) {
                        continue;
                    }

                    if ($startDate && $date->lt(CarbonImmutable::instance($startDate)->startOfDay())) {
                        continue;
                    }
                    if ($endDate && $date->gt(CarbonImmutable::instance($endDate)->endOfDay())) {
                        continue;
                    }

                    $opensAt = $this->dateAtTime($date, (string) $startTime);
                    if ($opensAt->lte($now)) {
                        continue;
                    }

                    $closesAt = $endTime ? $this->dateAtTime($date, (string) $endTime) : null;
                    if ($closesAt && $closesAt->lte($opensAt)) {
                        $closesAt = $closesAt->addDay();
                    }

                    if ($nextOpening === null || $opensAt->lt($nextOpening['opens_at'])) {
                        $nextOpening = [
                            'menu' => $menu,
                            'opens_at' => $opensAt,
                            'closes_at' => $closesAt,
                        ];
                    }

                    break;
                }
            }
        }

        if ($nextOpening === null) {
            return null;
        }

        return [
            'branch_name' => (string) $branch->name,
            'menu_name' => (string) $nextOpening['menu']->name,
            'timezone' => $timezone,
            'next_opens_at' => $nextOpening['opens_at']->toIso8601String(),
            'next_closes_at' => $nextOpening['closes_at']?->toIso8601String(),
        ];
    }

    private function applyServiceTypeGate(Builder $query, ?string $serviceType): void
    {
        if ($serviceType === null) {
            return;
        }

        $wanted = [$serviceType, 'Both'];
        $query->where(function ($q) use ($wanted) {
            $q->whereIn('service_type', $wanted)
                ->orWhere(function ($inherit) use ($wanted) {
                    $inherit->whereNull('service_type')
                        ->where(function ($resolve) use ($wanted) {
                            $resolve->whereHas('masterMenu', fn ($masterQuery) => $masterQuery->whereIn('service_type', $wanted))
                                ->orWhereDoesntHave('masterMenu');
                        });
                });
        });
    }

    private function dateAtTime(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return $date->setTime($hour, $minute, $second);
    }

    /**
     * @param  array<string, MenuDisplayPromotion|null>  $promotionMap
     */
    private function transformMenu(Menu $menu, array $promotionMap = [], ?Branch $branch = null, ?string $timezone = null): array
    {
        $branch ??= $menu->branch;
        $timezone ??= $this->resolveBranchTimezone($branch);

        $grouped = $menu->menuProducts->groupBy('menu_section_id');

        // #1218 — per-section tax types for THIS menu, read once. The workstation
        // never sees the tiers separately: the feed collapses them into a single
        // `menu_items.tax_type_id` (see the tier_collapse_note in
        // tax_resolution_golden.json), so the collapse below has to walk the
        // same order the resolver does or LAN and Cloud price a line differently.
        $sectionTaxTypeIds = $menu->menuSections
            ->mapWithKeys(fn ($section) => [$section->id => $section->pivot->tax_type_id ?? null])
            ->all();

        $categories = [];
        foreach ($grouped as $sectionId => $menuProducts) {
            $section = $menuProducts->first()->menuSection;

            $items = [];
            foreach ($menuProducts as $menuProduct) {
                $product = $menuProduct->product;
                if (! $product) {
                    continue;
                }

                $skus = $menuProduct->menuProductSkus;
                $defaultSku = $skus->first();
                $price = $defaultSku?->selling_price ?? $defaultSku?->productSku?->selling_price ?? 0;

                $options = $this->transformOptions($product, $skus, (float) $price);
                $toppingGroups = $this->transformToppingGroups(
                    $product,
                    (string) $menuProduct->id,
                    $menuProduct->relationLoaded('toppingOverrides') ? $menuProduct->toppingOverrides : null,
                );
                $isCombo = $product->productType?->code === 'combo';

                // Plan-019 — overlay active_promotion block + discounted
                // price + ends_at (next window-end inside valid_until).
                $promotion = $promotionMap[$product->id] ?? null;
                $activePromotion = $promotion === null
                    ? null
                    : [
                        'id' => $promotion->id,
                        'name' => $promotion->name,
                        'discount_percent' => $promotion->discountPercent,
                        'discounted_price' => round(
                            ((float) $price) * (100 - $promotion->discountPercent) / 100,
                            2,
                            PHP_ROUND_HALF_UP,
                        ),
                        'stacking_mode' => $promotion->stackingMode,
                        'ends_at' => $promotion->endsAt,
                    ];

                $activeFloatingSection = $defaultSku?->getAttribute('active_floating_section');

                // Prefer the default SKU's first gallery photo when it
                // exists — the SKU-level gallery is the per-variant photo
                // (e.g. "Phở Bò - Lớn" gets its own shot) whereas the
                // product gallery is the generic hero. Fall back to the
                // product gallery for products that don't have SKU-level
                // photos yet.
                $primaryImage = $defaultSku?->productSku?->galleryFirst?->getUrl()
                    ?? $product->galleryFirst?->getUrl();

                $item = [
                    'id' => $menuProduct->id,
                    'sku_id' => $defaultSku?->productSku?->id,
                    // #514 — the EXACT menu line whose `price` is shown. The order
                    // endpoint prices by this id so the customer is charged the
                    // displayed price, not a different menu line for the same SKU
                    // (a product_sku can sit in several menu_product_skus).
                    'menu_product_sku_id' => $defaultSku?->id,
                    'name' => $product->name,
                    'description' => $product->description ? strip_tags($product->description) : null,
                    'price' => (float) $price,
                    'base_price' => $defaultSku?->getAttribute('base_selling_price'),
                    'active_floating_section' => $activeFloatingSection,
                    // plan-043 T3.2 — per-item consumption-tax hints for the
                    // workstation resolver. Null means the line inherits the
                    // branch/brand default on the workstation side.
                    //
                    // #1218 — this collapse now spans four tiers, not two:
                    // menu-item override → section-in-this-menu → whole menu →
                    // product. It must stay in the SAME order as
                    // TaxResolver::resolveType; the workstation resolves only
                    // what this column hands it, so a different order here means
                    // a receipt printed on the LAN disagrees with the invoice
                    // Cloud books for the same basket.
                    'tax_type_id' => $menuProduct->tax_type_id
                        ?? ($sectionTaxTypeIds[$menuProduct->menu_section_id] ?? null)
                        ?? $menu->tax_type_id
                        ?? $product->tax_type_id,
                    // #1099 single-rate — the effective rate this item will be
                    // billed at, resolved through the FULL chain (menu override →
                    // product → branch default → brand default) by the same
                    // TaxResolver the order engines use, so the menu hint cannot
                    // disagree with the invoice. Lets the client show 総額表示
                    // (tax-included) prices without a round-trip.
                    'tax_rate' => $this->resolveItemTaxRate($menuProduct, $product, (string) $menu->branch_id, $menu->brand_id),
                    'image' => $primaryImage,
                    'images' => $product->gallery
                        ->map(fn ($file) => $file->getUrl())
                        ->values()
                        ->all(),
                    'status' => $menuProduct->is_active ? 'available' : 'unavailable',
                    'options' => $options ?: null,
                    'toppingGroups' => $toppingGroups ?: null,
                    'is_combo' => $isCombo,
                    'active_promotion' => $activePromotion,
                    // Plan-025 — real review aggregate (replaces frontend mocks)
                    'rating' => $product->recommendPercent(),
                    'reviewCount' => $product->review_total_count,
                ];

                // For combo products, flatten topping group items as combo contents
                if ($isCombo && $toppingGroups) {
                    $item['comboItems'] = $this->extractComboItems($toppingGroups);
                }

                $items[] = $item;

            }

            $categories[] = [
                'id' => $sectionId ?: 'uncategorized',
                'name' => $section?->name ?? 'Other',
                // #1187 — the customer "featured" carousel reads this flag.
                // It used to scan the display name for "Nổi bật" / "Featured" /
                // "おすすめ" / "⭐" / "🔥", so renaming a section silently emptied
                // the carousel and no shop outside those languages could fill it.
                'is_featured' => (bool) ($section?->is_featured ?? false),
                'items' => $items,
            ];
        }

        // #1185 — order the menu's own sections by the curator's intent
        // (`menu_menu_sections.display_order`, exposed through Menu::menuSections
        // which already orders by that pivot). Sections the pivot does not know
        // about keep their previous relative order, after the ordered ones.
        $sectionOrder = [];
        foreach ($menu->menuSections as $position => $menuSection) {
            $sectionOrder[(string) $menuSection->id] = $position;
        }

        if ($sectionOrder !== []) {
            $fallback = count($sectionOrder);
            $categories = collect($categories)
                ->sortBy(fn (array $category, int $index) => [
                    $sectionOrder[(string) $category['id']] ?? $fallback,
                    $index,
                ])
                ->values()
                ->all();
        }

        // #1185 — the promo spotlight is NOT built here any more.
        // buildFloatingSectionCategories() assembles it from the
        // floating_section_* tables directly, so it also lists products that
        // are in an active floating section but in no menu at all — which this
        // menu-scoped collection could never see. Keeping both produced the
        // section twice.

        // Get the current active schedule's EFFECTIVE start/end times for this branch.
        // If a BranchScheduleOverride exists for the branch, we prefer its
        // times over the base schedule so the customer-web sees the same
        // window the backend uses for gating.
        $scheduleStartTime = null;
        $scheduleEndTime = null;
        $activeSchedule = $menu->activeSchedules->first();
        if ($activeSchedule) {
            $branchId = $branch?->id;

            if ($branchId) {
                $override = $activeSchedule->branchScheduleOverrides
                    ->firstWhere('branch_id', $branchId);

                if ($override) {
                    $scheduleStartTime = $override->start_time ?? $activeSchedule->start_time; // HH:MM:SS
                    $scheduleEndTime = $override->end_time ?? $activeSchedule->end_time; // HH:MM:SS
                } else {
                    $scheduleStartTime = $activeSchedule->start_time; // HH:MM:SS (base)
                    $scheduleEndTime = $activeSchedule->end_time; // HH:MM:SS (base)
                }
            } else {
                $scheduleStartTime = $activeSchedule->start_time; // HH:MM:SS (base)
                $scheduleEndTime = $activeSchedule->end_time; // HH:MM:SS (base)
            }
        }

        // Calculate cart timeout deadline (menu end + timeout minutes)
        // 4-tier cascade: menu → branch → master menu → brand
        $effectiveTimeout = $menu->cart_timeout_minutes
            ?? $branch?->cart_timeout_minutes
            ?? $menu->masterMenu?->cart_timeout_minutes
            ?? $branch?->brand?->cart_timeout_minutes
            ?? 30; // Fallback default

        $cartDeadlineIso = null;
        if ($scheduleEndTime) {
            $endTime = Carbon::parse($scheduleEndTime, $timezone);
            $cartDeadlineIso = $endTime->addMinutes($effectiveTimeout)->toIso8601String();
        }

        return [
            'menu_id' => $menu->id,
            'menu_name' => $menu->name,
            'schedule_start_time' => $scheduleStartTime, // HH:MM:SS or null
            'schedule_end_time' => $scheduleEndTime, // HH:MM:SS or null
            'cart_timeout_minutes' => $effectiveTimeout,
            'cart_deadline_iso' => $cartDeadlineIso,
            // Plan-026 — branch review aggregate for customer-web display
            'review_avg_rating' => $branch?->review_avg_rating ? (float) $branch->review_avg_rating : null,
            'review_total_count' => (int) ($branch?->review_total_count ?? 0),
            'categories' => $categories,
        ];
    }

    /**
     * plan-043 T5.4 — resolve the effective consumption-tax rate for a menu
     * item in a given order type. Follows the first two tiers of the
     * FULL TaxResolver chain — menu override → product → branch default → brand
     * default. It used to walk only the first two tiers, so an item that
     * inherited returned null and the client had to guess the rest; the hint
     * could then disagree with the invoice. Going through the SAME resolver the
     * order engines use means the menu shows exactly what the bill will say.
     *
     * Still a display hint: the authoritative rate is stamped per line at order
     * time. Null only when the brand has no tax type at all.
     */
    private function resolveItemTaxRate($menuProduct, $product, string $branchId, ?string $brandId): ?float
    {
        // One batch per menu build: it memoises the branch/brand default
        // lookups, so a 300-item menu costs one query each, not 300. #1596 moved
        // the resolver behind `MenuDisplayTaxRates`; the lifetime is unchanged —
        // one batch = one build, exactly what the locally constructed resolver
        // gave before.
        $this->menuTaxRates ??= $this->displayTaxRates->beginBatch();

        // #1218 — the display hint walks the SAME six tiers as the bill,
        // menu/section included. Skipping them here would put an 8% takeaway
        // menu on the receipt while the menu screen still advertised 10%.
        //
        // Ids, not models: `tax_type_id` is already on the row and the port
        // rehydrates it through the same soft-delete scope the relation used.
        return $this->menuTaxRates->rateForMenuLine(
            $menuProduct->tax_type_id,
            $product->tax_type_id,
            $branchId,
            (string) ($brandId ?? $product->brand_id),
            $menuProduct->menu_id,
            $menuProduct->menu_section_id,
        );
    }

    /**
     * Transform product options + SKUs into the frontend options/variants shape.
     *
     * @param  float  $basePrice  Default SKU selling price — used to compute variant price deltas.
     */
    private function transformOptions($product, $menuProductSkus, float $basePrice): array
    {
        $options = [];

        foreach ($product->options as $option) {
            $variants = [];

            foreach ($option->values as $value) {
                $matchingSku = $menuProductSkus->first(function ($mps) use ($value) {
                    $sku = $mps->productSku;

                    return $sku &&
                        ($sku->option_value1_id === $value->id
                        || $sku->option_value2_id === $value->id
                        || $sku->option_value3_id === $value->id);
                });

                // `$menuProductSkus` is already filtered to the branch's ACTIVE
                // menu_product_skus (see getMenuForBranch). A value with no
                // matching active SKU means every menu line carrying it was
                // deactivated at the shop — so it is unorderable and must not
                // appear. Skipping it (rather than emitting a sku_id=null
                // variant) is what keeps a shop-disabled variant off the
                // customer picker. A value backed by ≥1 active SKU still shows,
                // so a multi-option product (Size × Temp) only hides the value
                // once ALL of its SKUs are off.
                if ($matchingSku === null) {
                    continue;
                }

                // Price stored per-variant is a DELTA from the base price so the
                // frontend can simply add it: unitPrice = item.price + Σ variant.price
                $variantFullPrice = (float) ($matchingSku->selling_price ?? $basePrice);
                $priceDelta = $variantFullPrice - $basePrice;

                $variants[] = [
                    'id' => $value->id,
                    'sku_id' => $matchingSku->productSku?->id,
                    // #514 — exact menu line for this variant (see main item note).
                    'menu_product_sku_id' => $matchingSku->id,
                    'name' => $value->label,
                    'price' => $priceDelta,
                    'image' => $matchingSku->productSku?->galleryFirst?->getUrl(),
                    'default' => $value->position === 0,
                ];
            }

            // Every value in this option was deactivated → the option is not
            // selectable; drop it rather than render an empty required picker.
            if ($variants === []) {
                continue;
            }

            $options[] = [
                'id' => $option->id,
                'name' => $option->name,
                // Schema has no type/required columns yet — always single-select, always required.
                'type' => 'single',
                'required' => true,
                'variants' => $variants,
            ];
        }

        return $options;
    }

    /**
     * Extract a flat list of combo-included items from topping groups.
     * Used by the customer-web featured carousel to show "Combo includes: X, Y, Z".
     */
    private function extractComboItems(array $toppingGroups): array
    {
        $comboItems = [];
        foreach ($toppingGroups as $group) {
            foreach ($group['items'] as $item) {
                $comboItems[] = [
                    'name' => $item['name'],
                    'image' => $item['image'],
                ];
            }
        }

        return $comboItems;
    }

    /**
     * Transform product topping groups into the frontend shape.
     *
     * Frontend expects: toppingGroups[] → items[] → { id, name, price, image, variants?: [] }
     *
     * Variant-aware logic: if a topping item's product has options (e.g. Beef Pho → Size),
     * we return an array of `variants` where each variant = 1 SKU with its own price.
     *
     * Example: "Chọn phở" group → "Beef Pho" item → variants: [{ id, sku_id, name: "Regular", price: 0 }, { id, sku_id, name: "Large", price: 100 }]
     */
    private function transformToppingGroups($product, ?string $menuProductId = null, $shopToppingOverrides = null, ?string $floatingSectionProductId = null): array
    {
        $toppingGroups = [];

        foreach ($product->toppingGroups as $group) {
            // Apply per-product overrides from pivot
            $minSelect = $group->pivot->min_select_override ?? $group->min_select ?? 0;
            $maxSelect = $group->pivot->max_select_override ?? $group->max_select;

            $items = [];

            foreach ($group->items as $item) {
                $toppingProduct = $item->product;

                // Check if the topping product has options (e.g. Size)
                $hasOptions = $toppingProduct && $toppingProduct->options->isNotEmpty();

                // …but the option axis is the wrong signal ON ITS OWN (#1275).
                // `options` is eager-loaded filtered to is_active = true (see
                // getMenuForBranch), so deactivating the axis on a product whose
                // per-SKU price rows still exist dropped the topping to a single
                // line and made every variant but one UNORDERABLE — the customer
                // could not pick it and the order gate never saw it. Ask the data
                // that actually decides orderability instead: the item's price
                // rows that point at a live ProductSku.
                //
                // Deliberately ADDITIVE (`||`): anything that takes the variant
                // branch today keeps it, and a single-SKU topping stays on the
                // simple branch rather than growing a one-entry variant picker.
                // A wildcard row (product_sku_id NULL) is not a variant — it
                // carries a price, not an identity — so it never counts here.
                $orderableVariantRows = $item->skus->filter(
                    fn ($itemSku) => $itemSku->productSku !== null && $itemSku->productSku->is_active
                );

                if ($hasOptions || $orderableVariantRows->count() >= 2) {
                    // Variant-aware: map each ToppingGroupItemSku to a variant object
                    $variants = [];
                    foreach ($item->skus as $itemSku) {
                        $sku = $itemSku->productSku;
                        // Inactive variants are filtered out of the eager-load
                        // (see getMenuForBranch), but guard here too — a menu
                        // must never show a SKU the order gate would reject.
                        if (! $sku || ! $sku->is_active) {
                            continue;
                        }
                        // Shop/HQ marked this variant hidden → the order gate
                        // rejects it, so drop it from the picker.
                        if ($this->isToppingHidden($item, $sku->id, $group->id, $shopToppingOverrides)) {
                            continue;
                        }

                        // Variant label composed from the SKU's option values
                        // (e.g. "Ít / Nongs") — the same convention the HQ
                        // product screen uses. product_skus.name is usually null
                        // for option-based variants, so reading it showed
                        // "Default"; variant_label joins the option-value labels.
                        $variantLabel = $sku->variant_label ?: 'Default';

                        $variants[] = [
                            'id' => $itemSku->id,
                            'sku_id' => $sku->id,
                            'name' => $variantLabel,
                            // Effective price through the SAME resolver the order
                            // engine uses (HQ per-product override → base
                            // extra_price), so the displayed price can never
                            // disagree with what the customer is charged.
                            'price' => $this->resolveToppingPrice($item, $sku->id, $product->id, $group->id, $menuProductId, $floatingSectionProductId),
                            'default' => false, // ToppingGroupItemSku doesn't have is_default — assume first variant is default
                        ];
                    }

                    // Every variant was inactive → the topping item is not
                    // orderable; drop it rather than render an empty picker.
                    if (empty($variants)) {
                        continue;
                    }

                    // Mark first variant as default
                    $variants[0]['default'] = true;

                    $items[] = [
                        'id' => $item->id,
                        'name' => $toppingProduct->name,
                        'image' => $toppingProduct->galleryFirst?->getUrl(),
                        'default' => (bool) $item->is_default,
                        'variants' => $variants,
                    ];
                } else {
                    // Simple product (no options) — single SKU, single price.
                    // sku_id is required by the order endpoint
                    // (CustomerOrderService::validateAndPriceToppings expects
                    // each selected topping row to carry product_sku_id).
                    $skuId = $this->resolveSimpleToppingSkuId($item);

                    // Shop/HQ marked this simple topping hidden → the order gate
                    // rejects it, so drop it from the group entirely.
                    if ($this->isToppingHidden($item, $skuId, $group->id, $shopToppingOverrides)) {
                        continue;
                    }

                    $extraPrice = $this->resolveToppingPrice($item, $skuId, $product->id, $group->id, $menuProductId, $floatingSectionProductId);

                    $items[] = [
                        'id' => $item->id,
                        'sku_id' => $skuId,
                        'name' => $toppingProduct?->name ?? 'Unknown',
                        'price' => $extraPrice,
                        'image' => $toppingProduct?->galleryFirst?->getUrl(),
                        'default' => (bool) $item->is_default,
                    ];
                }
            }

            $toppingGroups[] = [
                'id' => $group->id,
                'name' => $group->name,
                'min_select' => $minSelect,
                'max_select' => $maxSelect,
                'max_qty_per_item' => $group->max_qty_per_item ?? 1,
                'items' => $items,
            ];
        }

        return $toppingGroups;
    }

    /**
     * Effective per-unit topping price for the menu display.
     *
     * Delegates to the SAME resolver the order engine uses
     * (`ToppingPricingService::resolveSnapshotPrice`: shop override → HQ
     * per-product override → base `extra_price`), so the displayed price can
     * never disagree with the charged price. When the sku id is unknown (a
     * simple topping carrying only the wildcard NULL row) or no price row
     * exists, fall back to the raw `extra_price` already loaded on the item's
     * SKU collection — no query.
     */
    private function resolveToppingPrice(ToppingGroupItem $item, ?string $productSkuId, string $productId, string $toppingGroupId, ?string $menuProductId = null, ?string $floatingSectionProductId = null): float
    {
        if ($productSkuId !== null) {
            try {
                return $this->toppingPricing->resolveSnapshotPrice(
                    $item->id,
                    $productSkuId,
                    $productId,
                    $toppingGroupId,
                    // Menu line → shop override (tier 1) so the displayed price
                    // matches the order engine + workstation. For a floating
                    // section item the owner is the floating_section_product;
                    // both feed the same tier-1 resolution.
                    $menuProductId,
                    $floatingSectionProductId,
                );
            } catch (ValidationException) {
                // No price row for this (item, sku) — fall through to the raw
                // extra_price snapshot below.
            }
        }

        $fallbackSku = $item->skus->firstWhere('product_sku_id', $productSkuId)
            ?? $item->skus->firstWhere('product_sku_id', null)
            ?? $item->skus->first();

        return (float) ($fallbackSku?->extra_price ?? 0);
    }

    /**
     * Whether this (topping item, sku) is hidden for the current menu line.
     *
     * Mirrors MenuToppingGroupItemResource: the SHOP override
     * (menu_product_topping_item_overrides, eager-loaded as $shopOverrides)
     * wins over the HQ override (product_topping_group_item_overrides, loaded
     * on the item as `productOverrides`). Within each tier a row scoped to the
     * exact product_sku_id wins over the wildcard (product_sku_id NULL) row. A
     * hidden topping is unorderable, so the customer menu must not offer it.
     */
    private function isToppingHidden(ToppingGroupItem $item, ?string $productSkuId, string $toppingGroupId, $shopOverrides): bool
    {
        // Tier 1 — shop override for this menu line.
        if ($shopOverrides !== null) {
            $rows = $shopOverrides->filter(
                fn ($ov) => $ov->topping_group_item_id === $item->id
                    && $ov->topping_group_id === $toppingGroupId
                    && ($ov->product_sku_id === $productSkuId || $ov->product_sku_id === null)
            );
            $match = $rows->firstWhere('product_sku_id', $productSkuId)
                ?? $rows->firstWhere('product_sku_id', null);
            if ($match !== null) {
                return (bool) $match->is_hidden;
            }
        }

        // Tier 2 — HQ per-product override (loaded on the item as
        // `productOverrides`; keyed by parent product × group × item × sku).
        if ($item->relationLoaded('productOverrides')) {
            $rows = $item->productOverrides->filter(
                fn ($ov) => $ov->topping_group_id === $toppingGroupId
                    && ($ov->product_sku_id === $productSkuId || $ov->product_sku_id === null)
            );
            $match = $rows->firstWhere('product_sku_id', $productSkuId)
                ?? $rows->firstWhere('product_sku_id', null);
            if ($match !== null) {
                return (bool) $match->is_hidden;
            }
        }

        return false;
    }

    /**
     * Resolve the `product_sku_id` customer-web must send back for a simple
     * (non-variant) topping selection.
     *
     * `ToppingGroupItemService::createItem` stores a wildcard
     * `topping_group_item_skus` row with `product_sku_id = NULL` for every
     * non-variant topping — that row carries the price, not the SKU identity.
     * Emitting its NULL as `sku_id` left the menu with an unorderable topping:
     * `mapCartItemToppings` (customer-web) drops selections it cannot map to a
     * SKU, so a mandatory group came back empty and the order failed with
     * `422 toppings_below_min ... no selection was provided`.
     *
     * Fall back to the topping product's own active SKU — the same SKU
     * `ToppingPricingService::resolveSnapshotPrice` prices via the wildcard row.
     */
    private function resolveSimpleToppingSkuId(ToppingGroupItem $item): ?string
    {
        $pivotSkuId = $item->skus->first(fn ($itemSku) => $itemSku->productSku !== null)?->productSku?->id;
        if ($pivotSkuId !== null) {
            return (string) $pivotSkuId;
        }

        return $item->product?->skus->first()?->id;
    }

    /**
     * Which clock this branch's menu windows are judged by (#1091).
     *
     * Delegates to BusinessClock — the ONE place that resolves a shop's zone —
     * so the customer menu, the HQ menu schedule and promotions can never
     * disagree about when a shop's day turns over. This used to be a private
     * copy of the same three lines; BusinessClock additionally caches per branch
     * and warns once when a branch has no timezone, which the copy did silently.
     *
     * The already-loaded model is honoured when it carries a zone so the common
     * path stays query-free.
     */
    private function resolveBranchTimezone(?Branch $branch): string
    {
        if ($branch && is_string($branch->timezone) && $branch->timezone !== '') {
            return $branch->timezone;
        }

        return BusinessClock::timezoneForBranch($branch?->id === null ? null : (string) $branch->id);
    }
}
