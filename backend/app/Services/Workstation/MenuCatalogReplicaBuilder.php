<?php

namespace App\Services\Workstation;

use App\Models\File;
use App\Models\Product;
use App\Models\ProductSku;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the flat sync-DOWN menu-catalog feed the workstation's PullMenuCatalog
 * consumes. Returns the 5+ menu-relation tables pre-joined and flattened so the
 * workstation iterates each array and INSERTs row-by-row without walking
 * relations on its side.
 *
 * Extracted verbatim from MenuCatalogReplicaController::index (plan-047
 * thin-controller/fat-service). The emitted shape is a contract with the
 * workstation puller — it must not drift.
 *
 * Real backend schema (not the column names originally assumed):
 *   - `menus`: priority (NOT display_order), status (NOT is_active), no
 *     schedule blob.
 *   - `menu_sections`: only (id, name). No is_active. Sort order lives on the
 *     pivot `menu_menu_sections.display_order`.
 *   - `menu_products`: (menu_id, product_id, menu_section_id, is_active,
 *     display_order).
 *   - `menu_product_skus`: (menu_product_id, product_sku_id, selling_price
 *     override, is_active).
 *   - `products`: status + is_hidden (no is_active column). SKUs selling_price +
 *     is_active live on product_skus.
 *
 * ## plan-056 — the feed carries TURNED-OFF rows, and that is load-bearing
 *
 * Until plan-056 both `menu_products` and `menu_product_skus` were filtered to
 * `is_active = true` here, so the flag this builder emits was always `1` and a
 * workstation could not tell "on" from "does not exist". That made the POS
 * unable to show — let alone edit — a dish the shop had turned off, and it also
 * hid a live defect: a SKU turned off in menu A but on in menu B still shipped
 * (menu B put its `product_sku_id` in scope) and was orderable in BOTH.
 *
 * The feed now ships turned-off rows. Two rules keep that from moving money,
 * and both look like redundancy until you try to remove one:
 *
 *   1. `$mpSkusAll` (unfiltered) drives SCOPE and the new `menu_product_skus`
 *      array. `$mpSkus` (active only) drives PRICE — `$overrideBySku` and
 *      nothing else. They are NOT interchangeable: `$overrideBySku` keys by
 *      `product_sku_id` and keeps the FIRST row it meets, with no ORDER BY, so
 *      feeding it turned-off rows lets a dead row's price win over the live
 *      one. That is a wrong price on a real receipt, arrived at silently.
 *   2. The per-menu-product state lives ONLY on the new `menu_product_skus`
 *      array, never folded into `skus[]`. `skus[]` is keyed by product_sku and
 *      is what the ordering screen reads; folding would reintroduce exactly the
 *      cross-menu collapse rule 1 exists to contain.
 *
 * `MenuCatalogPriceCollapseUnchangedTest` fails if either rule is undone.
 *
 * Raw joined queries keep the surface decoupled from Eloquent relation
 * surprises. Out of scope for v1: gallery/options/topping_groups fall back to a
 * placeholder image + skipped topping dialog when replicas are missing.
 *
 * Floating sections (#1180) are replicated too — seasonal/promo spotlights that
 * `CustomerMenuService::buildFloatingSectionCategories` serves online. They are
 * built from the `floating_section_*` tables and are INDEPENDENT of any menu, so
 * a spotlight-only product (in no menu at all) would otherwise be unsellable on
 * a workstation that has lost the internet. Two rules mirror the online path:
 *
 *   - The schedule rows ship RAW. Cloud does not pre-filter "is this section
 *     live right now", because the workstation may run for hours between pulls
 *     and has to evaluate the window against its own clock.
 *   - The TAX TIER IS COLLAPSED HERE, into one `floating_section_products
 *     .tax_type_id` = `FloatingSectionProduct.tax_type_id ?? Product.tax_type_id`
 *     — byte-for-byte the value CustomerMenuService emits for the same product.
 *     The workstation must never re-walk the tiers in Go: a second walk is a
 *     second thing to drift, and drift here means the shop hands the customer a
 *     receipt at one consumption-tax rate and books the sale at another. A null
 *     collapses to null on purpose (inherit) — the device's own resolver then
 *     continues to the branch/brand default exactly as Cloud's does.
 *
 * The floating rows also EXPAND the product/sku scope of the blocks below, so a
 * spotlight-only product still ships its name, image, options and topping
 * groups. Its promo price stays on `floating_section_product_skus.selling_price`
 * and never touches `skus.selling_price` — that column is the menu price, and
 * writing a promo into it would re-price the same SKU sold from a normal menu.
 */
class MenuCatalogReplicaBuilder
{
    /**
     * Build the catalog feed for a branch, or the empty shape when the branch id
     * is blank (unpaired device) or the branch publishes no menus.
     *
     * The no-menus early return takes the floating sections with it, which is
     * the online behaviour too: `CustomerMenuService::getMenuForBranch` returns
     * null before it ever reaches `buildFloatingSectionCategories`, so a branch
     * with a spotlight but no menu serves nothing on either path.
     *
     * @return array<string, list<mixed>>
     */
    public function buildForBranch(?string $branchId): array
    {
        if (! $branchId) {
            return $this->emptyShape();
        }

        // 1) Menus for this branch (published only). `priority` lower =
        // more important, so order ascending — matches workstation's
        // sort_order semantics.
        // Join the master menu so a branch menu with NULL service_type can
        // resolve its effective type (#463 inherit rule) here — the workstation
        // mirror only stores the already-resolved value.
        $menus = DB::table('menus as m')
            ->leftJoin('menus as master', 'm.master_menu_id', '=', 'master.id')
            ->whereNull('m.deleted_at')
            ->where('m.branch_id', $branchId)
            ->whereIn('m.status', ['published', 'Published', 'active', 'Active'])
            ->orderBy('m.priority')
            ->orderBy('m.name')
            ->get([
                'm.id', 'm.name', 'm.description', 'm.status', 'm.priority',
                'm.service_type', 'master.service_type as master_service_type',
            ]);

        if ($menus->isEmpty()) {
            return $this->emptyShape();
        }

        $menuIds = $menus->pluck('id')->all();

        // 2) Sections via the menu_menu_sections pivot. `menu_sections`
        // itself has no sort order — pivot.display_order is the source.
        $sections = DB::table('menu_menu_sections as mms')
            ->join('menu_sections as ms', 'ms.id', '=', 'mms.menu_section_id')
            ->whereIn('mms.menu_id', $menuIds)
            ->whereNull('ms.deleted_at')
            ->orderBy('mms.menu_id')
            ->orderBy('mms.display_order')
            ->get([
                'ms.id as id',
                'mms.menu_id as menu_id',
                'ms.name as name',
                'mms.display_order as display_order',
            ]);

        // 3) menu_products for these menus.
        //
        // Skip menu_products whose base product is soft-deleted. The
        // `products` block below filters `whereNull('p.deleted_at')`, so a
        // menu_product pointing at a since-deleted product would keep the
        // menu_product + its menu_product_skus in the feed while the parent
        // product row is dropped — the workstation then inserts a
        // pos_product_skus row referencing a non-existent pos_products id and
        // the whole catalog transaction aborts with SQLite FK error 787,
        // leaving pos_menus empty (POS shows no menu at all). Mirror the
        // customer/kiosk feed, where Eloquent's SoftDeletes global scope on
        // `whereHas('product')` already hides deleted products.
        // plan-056 — NO `is_active` filter. A dish the shop turned off must
        // reach the workstation carrying `is_active = false` so the POS "Tồn
        // món" screen can show it and turn it back on while offline. The
        // ORDERING surface is unaffected: the workstation's read path filters
        // on this same flag (`local_pos_menus.go`), so what the cashier picks
        // from is byte-identical to before. Do not re-add the filter here to
        // "fix" a screen showing too much — filter at the read, not the feed,
        // or the shop loses the ability to switch a dish back on.
        $menuProducts = DB::table('menu_products')
            ->whereNull('menu_products.deleted_at')
            ->whereIn('menu_id', $menuIds)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('products as mp_p')
                    ->whereColumn('mp_p.id', 'menu_products.product_id')
                    ->whereNull('mp_p.deleted_at');
            })
            ->orderBy('menu_id')
            ->orderBy('display_order')
            // #3170 — the replica must land on the SAME sequence POS and the
            // customer menu render; `display_order` ties (104/127 rows on 0)
            // leave that to the query plan otherwise.
            ->orderBy('menu_products.id')
            ->get([
                'id', 'menu_id', 'product_id', 'menu_section_id', 'is_active', 'display_order',
                'disabled_reason', 'disabled_at', 'disabled_by_name',
            ]);

        $menuProductIds = $menuProducts->pluck('id')->all();

        // 3a) Floating-section replicas (#1180) — loaded BEFORE the topping and
        // product blocks for the same reason the topping block is: a spotlight
        // product need not be in ANY menu, so its id has to be folded into the
        // product scope below or the workstation receives a floating row whose
        // product has no name, no image, no options and no topping groups.
        //
        // Sections ship even when their window is closed right now: the device
        // owns the "is it live" decision (see the class docblock).
        $floatingSections = DB::table('floating_sections')
            ->whereNull('deleted_at')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('name')
            ->get(['id', 'name', 'priority', 'is_active', 'start_date', 'end_date']);

        $floatingSectionIds = $floatingSections->pluck('id')->all();

        $floatingSchedules = empty($floatingSectionIds) ? collect() : DB::table('floating_section_schedules')
            ->whereNull('deleted_at')
            ->whereIn('floating_section_id', $floatingSectionIds)
            ->where('is_active', true)
            ->orderBy('floating_section_id')
            ->orderBy('priority')
            ->get([
                'id', 'floating_section_id', 'days_of_week',
                'start_time', 'end_time', 'start_date', 'end_date',
                'is_active', 'priority',
            ]);

        // The join to `products` is the FK-787 guard the menu_products block
        // documents (a floating row pointing at a since-deleted product would
        // survive while the product row is dropped) AND the carrier of the
        // second tax tier: `products.tax_type_id` is what a null override
        // inherits, collapsed into one column in the emit block below.
        $floatingProducts = empty($floatingSectionIds) ? collect() : DB::table('floating_section_products as fsp')
            ->join('products as fsp_p', 'fsp_p.id', '=', 'fsp.product_id')
            ->whereNull('fsp.deleted_at')
            ->whereNull('fsp_p.deleted_at')
            ->whereIn('fsp.floating_section_id', $floatingSectionIds)
            ->where('fsp.is_active', true)
            ->orderBy('fsp.floating_section_id')
            ->orderBy('fsp.display_order')
            ->get([
                'fsp.id', 'fsp.floating_section_id', 'fsp.product_id',
                'fsp.is_active', 'fsp.display_order',
                'fsp.tax_type_id as override_tax_type_id',
                'fsp_p.tax_type_id as product_tax_type_id',
            ]);

        $floatingProductIds = $floatingProducts->pluck('id')->all();

        // Same soft-delete guard on the SKU side: `product_skus` below filters
        // deleted rows out, so a floating sku pointing at a dead catalog sku
        // would reference a pos_product_skus row that never gets inserted.
        $floatingProductSkus = empty($floatingProductIds) ? collect() : DB::table('floating_section_product_skus as fsps')
            ->join('product_skus as fsps_s', 'fsps_s.id', '=', 'fsps.product_sku_id')
            ->whereNull('fsps.deleted_at')
            ->whereNull('fsps_s.deleted_at')
            ->whereIn('fsps.floating_section_product_id', $floatingProductIds)
            ->where('fsps.is_active', true)
            ->orderBy('fsps.floating_section_product_id')
            ->get([
                'fsps.id', 'fsps.floating_section_product_id', 'fsps.product_sku_id',
                'fsps.selling_price', 'fsps.is_active', 'fsps.is_price_overridden',
            ]);

        // Menu products ∪ floating-section products — everything the topping,
        // product, gallery and option blocks below must cover.
        $baseProductIds = collect($menuProducts->pluck('product_id'))
            ->merge($floatingProducts->pluck('product_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // 3b) Topping replicas — loaded BEFORE the product/gallery/
        // options/skus blocks because the topping product (e.g.
        // "Cheese") referenced by `topping_group_items.product_id` is
        // typically NOT in any menu_products row. Without the topping
        // product_ids folded into the productIds set, the workstation
        // never receives the topping product's name + image_url +
        // gallery + options + its own product_skus → pos-web's topping
        // dialog renders without thumbnails or variant labels.
        $productToppingPivot = empty($baseProductIds) ? collect() : DB::table('product_topping_groups')
            ->whereIn('product_id', $baseProductIds)
            ->orderBy('product_id')
            // Tie-break on the pivot's unique id (#2109) — without it the replica
            // feed could hand workstation/kiosk/KDS a different topping-GROUP
            // order than the cloud menu shows.
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'product_id', 'topping_group_id', 'sort_order', 'min_select_override', 'max_select_override']);

        $toppingGroupIds = $productToppingPivot->pluck('topping_group_id')->unique()->all();
        // NOTE: `max_qty_per_item`, `available_from`, `available_to`,
        // `available_days` were dropped by 2000_02_13's alter
        // (omnify-generated). Don't SELECT them — the MenuToppingGroup
        // Resource emits a hard-coded 1 default via `?? 1`, which we
        // mirror in the emit block below.
        $toppingGroups = empty($toppingGroupIds) ? collect() : DB::table('topping_groups')
            ->whereIn('id', $toppingGroupIds)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get([
                'id', 'name', 'selection_type', 'modifier_type', 'price_strategy',
                'free_quantity', 'min_select', 'max_select',
                'sort_order', 'is_active',
            ]);

        $loadedGroupIds = $toppingGroups->pluck('id')->all();
        // Skip topping items whose component product is soft-deleted. Same
        // hazard as the menu_products block above: the `products` query filters
        // `whereNull('p.deleted_at')`, so a topping item pointing at a
        // since-deleted product would keep its topping_group_item_skus in the
        // feed while the parent product row is dropped — the workstation then
        // inserts a pos_product_skus row referencing a non-existent
        // pos_products id and the whole catalog transaction aborts with SQLite
        // FK error 787, leaving pos_menus empty (POS shows no menu at all).
        $toppingItems = empty($loadedGroupIds) ? collect() : DB::table('topping_group_items')
            ->whereIn('topping_group_id', $loadedGroupIds)
            ->whereNull('topping_group_items.deleted_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('products as tgi_p')
                    ->whereColumn('tgi_p.id', 'topping_group_items.product_id')
                    ->whereNull('tgi_p.deleted_at');
            })
            ->orderBy('topping_group_id')
            // `sort_order` is not unique (#2046) — without this the replica feed
            // could hand workstation/kiosk/KDS a different topping order than
            // the cloud menu shows. `id` is UUIDv7: unique and time-sortable.
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'topping_group_id', 'product_id', 'sort_order', 'is_default']);

        $itemIds = $toppingItems->pluck('id')->all();
        $itemSkus = empty($itemIds) ? collect() : DB::table('topping_group_item_skus')
            ->whereIn('topping_group_item_id', $itemIds)
            ->get(['id', 'topping_group_item_id', 'product_sku_id', 'extra_price']);

        // Expand the product + sku scope so the topping product itself
        // ships with name + image_url + gallery + options. The expanded
        // sets are what every subsequent query joins against.
        $productIds = collect($baseProductIds)
            ->merge($toppingItems->pluck('product_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // 4) Products referenced — emit a status-derived is_active +
        // the product_type_code that pos-web's MenuCatalog reads to
        // switch the combo card variant.
        $productsRaw = empty($productIds) ? collect() : DB::table('products as p')
            ->leftJoin('product_types as pt', 'pt.id', '=', 'p.product_type_id')
            ->whereNull('p.deleted_at')
            ->whereIn('p.id', $productIds)
            ->get([
                'p.id', 'p.name', 'p.description', 'p.status', 'p.is_hidden',
                'pt.code as product_type_code',
            ]);

        // Locale-resolved product names. The base `products.name` column can be
        // empty for some rows, and the WS mirrors a single `pos_products.name`
        // column — so an empty name here means a blank product offline. Pull
        // translations and resolve a non-empty, locale-best name below
        // (request locale → vi → ja → en → any → base column).
        $productNameByLocale = [];
        if (! empty($productIds)) {
            foreach (DB::table('product_translations')
                ->whereIn('product_id', $productIds)
                ->get(['product_id', 'locale', 'name']) as $tr) {
                if (is_string($tr->name) && trim($tr->name) !== '') {
                    $productNameByLocale[$tr->product_id][$tr->locale] = $tr->name;
                }
            }
        }

        // 4b) Product gallery — every File attached to the products in
        // play, ordered so the first row resolves the same way as
        // ProductResource.image_url (galleryFirst). The morph columns
        // on `files` are `fileable_*`, not `attachable_*` — that's the
        // schema name in 2000_01_01_000146_create_files_table. Public
        // `url` is exposed via the File model accessor (computes
        // `disk + path`), not stored as a column, so we read the raw
        // pieces here and resolve via the model below.
        // Use `(new X)->getMorphClass()` rather than `X::class` because
        // OmnifyServiceProvider::boot calls Relation::enforceMorphMap()
        // → `files.fileable_type` stores the SHORT alias ("Product",
        // "ProductSku"), NOT the FQCN. `Product::class` =
        // "App\Models\Product" matches zero rows even when 234 product
        // images exist. This was the silent reason workstation never
        // pulled any image bytes — the menu-catalog feed returned 0
        // galleries despite the shop having uploaded files.
        $productMorph = (new Product)->getMorphClass();
        $skuMorph = (new ProductSku)->getMorphClass();
        $galleryFiles = empty($productIds) ? collect() : File::query()
            ->whereIn('fileable_id', $productIds)
            ->where('fileable_type', $productMorph)
            ->whereNull('deleted_at')
            ->orderBy('fileable_id')
            ->orderBy('sort_order')
            ->get(['id', 'fileable_id', 'disk', 'path', 'original_name', 'mime_type', 'sort_order']);
        $galleries = $galleryFiles->map(fn ($f) => (object) [
            'id' => $f->id,
            'product_id' => $f->fileable_id,
            'url' => (string) $f->getUrl(),
            'original_name' => $f->original_name,
            'mime_type' => $f->mime_type,
            'sort_order' => $f->sort_order,
        ]);

        // First gallery row per product → image_url cache for the
        // product card thumbnail (matches the galleryFirst eager-load
        // ProductResource uses to populate image_url).
        $imageUrlByProductId = [];
        foreach ($galleries as $g) {
            if (! isset($imageUrlByProductId[$g->product_id])) {
                $imageUrlByProductId[$g->product_id] = (string) $g->url;
            }
        }

        // 5) menu_product_skus.
        //
        // ⚠️ TWO COLLECTIONS ON PURPOSE — DO NOT MERGE THEM (plan-056).
        //
        // `$mpSkusAll` is unfiltered: it feeds `$skuIds` (so a turned-off
        // variant still ships its label/code/options and the POS can switch it
        // back on) and the `menu_product_skus` emit block, which is the ONLY
        // place per-menu-product variant state survives the trip.
        //
        // `$mpSkus` keeps the `is_active = true` filter and exists for exactly
        // one consumer: `$overrideBySku` below. That map keys by
        // `product_sku_id` and keeps the FIRST row it sees, with no ORDER BY —
        // so handing it turned-off rows lets a dead row's price beat the live
        // one, non-deterministically, on a real receipt. The filter is the
        // whole defence.
        $mpSkusAll = empty($menuProductIds) ? collect() : DB::table('menu_product_skus')
            ->whereNull('deleted_at')
            ->whereIn('menu_product_id', $menuProductIds)
            ->get([
                'id', 'menu_product_id', 'product_sku_id', 'selling_price', 'is_active',
                'is_price_overridden', 'disabled_reason', 'disabled_at', 'disabled_by_name',
            ]);

        $mpSkus = $mpSkusAll->where('is_active', true);

        // Expand sku scope to include topping_group_item_skus.product_sku_id
        // so the workstation can resolve sku_label / sku_code for the
        // topping picker (MenuToppingGroupItemSkuResource reads
        // productSku.name + productSku.sku when productSku is loaded).
        // …and to include floating_section_product_skus.product_sku_id (#1180),
        // so a spotlight-only SKU ships its label/code/variant option values.
        // Its `skus.selling_price` stays the MENU price (no menu row → the
        // catalog price); the promo lives on the floating sku row alone.
        // plan-056 — scope comes from `$mpSkusAll`, not `$mpSkus`: a variant the
        // shop turned off still needs its name, code and option values shipped,
        // or the "Tồn món" screen renders a blank row nobody can identify and
        // the shop cannot switch it back on offline.
        $skuIds = collect($mpSkusAll->pluck('product_sku_id'))
            ->merge($itemSkus->pluck('product_sku_id'))
            ->merge($floatingProductSkus->pluck('product_sku_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $productSkus = empty($skuIds) ? collect() : DB::table('product_skus')
            ->whereNull('deleted_at')
            ->whereIn('id', $skuIds)
            ->get([
                'id', 'product_id', 'name', 'sku', 'selling_price', 'is_active',
                'option_value1_id', 'option_value2_id', 'option_value3_id',
            ]);

        // 5b) Per-SKU gallery — same File table, fileable_type=ProductSku.
        // Mirrors productSku.galleryFirst on the read path so pos-web's
        // cart line + SKU table can render the variant's own thumbnail
        // instead of falling back to the product image.
        $skuGalleryFiles = empty($skuIds) ? collect() : File::query()
            ->whereIn('fileable_id', $skuIds)
            ->where('fileable_type', $skuMorph)
            ->whereNull('deleted_at')
            ->orderBy('fileable_id')
            ->orderBy('sort_order')
            ->get(['id', 'fileable_id', 'disk', 'path']);
        $imageUrlBySkuId = [];
        foreach ($skuGalleryFiles as $f) {
            if (! isset($imageUrlBySkuId[$f->fileable_id])) {
                $imageUrlBySkuId[$f->fileable_id] = (string) $f->getUrl();
            }
        }

        // Build keyed lookups.
        // ⚠️ Iterates `$mpSkus` (ACTIVE ONLY), never `$mpSkusAll`. "First row
        // wins" with no ORDER BY is safe only while every candidate is a row
        // the shop is actually selling from; a turned-off row in here can beat
        // the live one and put a stale price on a receipt (plan-056).
        $overrideBySku = []; // product_sku_id → override selling_price
        $isOverriddenBySku = []; // product_sku_id → bool (any mp row flagged)
        foreach ($mpSkus as $row) {
            if (! isset($overrideBySku[$row->product_sku_id])) {
                $overrideBySku[$row->product_sku_id] = (float) $row->selling_price;
            }
            if (! isset($isOverriddenBySku[$row->product_sku_id])) {
                $isOverriddenBySku[$row->product_sku_id] = (bool) $row->is_price_overridden;
            }
        }

        // ─── Plan-022 — options + option_values + topping_groups + overrides ───

        // 6) Product options + values for variant labels
        // (productSku.optionValue1/2/3.option chain).
        $productOptions = empty($productIds) ? collect() : DB::table('product_options')
            ->whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->orderBy('product_id')
            ->orderBy('position')
            ->get(['id', 'product_id', 'key', 'name', 'position', 'is_active']);

        $optionIds = $productOptions->pluck('id')->all();
        $optionValues = empty($optionIds) ? collect() : DB::table('product_option_values')
            ->whereIn('option_id', $optionIds)
            ->where('is_active', true)
            ->orderBy('option_id')
            ->orderBy('position')
            ->get(['id', 'option_id', 'value', 'label', 'position', 'is_active']);

        // Tier-2 overrides — per-product item-sku. Scoped against the
        // expanded productIds so a tier-2 row attached to a topping
        // product still propagates.
        $productItemOverrides = empty($productIds) || empty($itemIds) ? collect() : DB::table('product_topping_group_item_overrides')
            ->whereIn('product_id', $productIds)
            ->whereIn('topping_group_item_id', $itemIds)
            ->get(['id', 'product_id', 'topping_group_item_id', 'product_sku_id', 'override_price', 'is_hidden']);

        // Tier-1 overrides — per menu_product item-sku (shop-level).
        $menuProductOverrides = empty($menuProductIds) || empty($itemIds) ? collect() : DB::table('menu_product_topping_item_overrides')
            ->whereIn('menu_product_id', $menuProductIds)
            ->whereIn('topping_group_item_id', $itemIds)
            ->get(['id', 'menu_product_id', 'topping_group_id', 'topping_group_item_id', 'product_sku_id', 'is_hidden', 'override_price']);

        // Tier-1 overrides for the spotlight — the floating-section twin of
        // menu_product_topping_item_overrides. A promo that also discounts (or
        // hides) a topping must price identically offline. No deleted_at on
        // this table.
        $floatingItemOverrides = empty($floatingProductIds) || empty($itemIds) ? collect() : DB::table('floating_section_product_topping_item_overrides')
            ->whereIn('floating_section_product_id', $floatingProductIds)
            ->whereIn('topping_group_item_id', $itemIds)
            ->get(['id', 'floating_section_product_id', 'topping_group_id', 'topping_group_item_id', 'product_sku_id', 'is_hidden', 'override_price']);

        // ---- Emit in the shape the workstation puller expects. ----

        $menusOut = [];
        foreach ($menus as $i => $m) {
            $menusOut[] = [
                'id' => $m->id,
                'name' => $m->name,
                'description' => $m->description,
                'status' => (string) $m->status,
                'sort_order' => (int) ($m->priority ?? $i),
                // #481 — effective service type (own value, else inherit the
                // master menu's, else Both). The workstation stores this
                // verbatim and gates the LAN menu list on it.
                'service_type' => $m->service_type ?? $m->master_service_type ?? 'Both',
            ];
        }

        $sectionsOut = [];
        foreach ($sections as $s) {
            $sectionsOut[] = [
                'id' => $s->id,
                'menu_id' => $s->menu_id,
                'name' => $s->name,
                'sort_order' => (int) ($s->display_order ?? 0),
                // No per-section active flag in schema — surface true so
                // the workstation doesn't hide rows by default.
                'is_active' => true,
            ];
        }

        $menuProductsOut = [];
        foreach ($menuProducts as $mp) {
            $menuProductsOut[] = [
                'id' => $mp->id,
                'menu_id' => $mp->menu_id,
                'product_id' => $mp->product_id,
                'menu_section_id' => $mp->menu_section_id,
                'is_active' => (bool) $mp->is_active,
                'display_order' => (int) ($mp->display_order ?? 0),
                // plan-056 — WHY it is off, for the POS "Tồn món" screen. All
                // three are null while the dish is on.
                'disabled_reason' => $mp->disabled_reason,
                'disabled_at' => $this->isoOrNull($mp->disabled_at),
                'disabled_by_name' => $mp->disabled_by_name,
            ];
        }

        // plan-056 — the per-menu-product variant pivot, shipped whole.
        //
        // This array is the ONLY carrier of two things the rest of the feed
        // structurally cannot express:
        //   · `id` — the Cloud `menu_product_skus` UUID. `skus[]` is keyed by
        //     product_sku, so without this the workstation has no address to
        //     write a variant toggle back to.
        //   · per-(menu_product, variant) state. The same product_sku can sit
        //     in two menus with different availability; `skus[]` collapses that
        //     by construction.
        //
        // `selling_price` / `is_price_overridden` ride along for DISPLAY ONLY —
        // the management screen shows the shop price read-only next to each
        // variant. The ordering path must keep reading `skus[].selling_price`;
        // pricing from this array would resurrect the cross-menu collapse.
        $menuProductSkusOut = $mpSkusAll->map(fn ($r) => [
            'id' => $r->id,
            'menu_product_id' => $r->menu_product_id,
            'product_sku_id' => $r->product_sku_id,
            'is_active' => (bool) $r->is_active,
            'selling_price' => (int) round((float) $r->selling_price),
            'is_price_overridden' => (bool) $r->is_price_overridden,
            'disabled_reason' => $r->disabled_reason,
            'disabled_at' => $this->isoOrNull($r->disabled_at),
            'disabled_by_name' => $r->disabled_by_name,
        ])->values()->all();

        $productsOut = [];
        foreach ($productsRaw as $p) {
            $isActive = in_array(strtolower((string) $p->status), ['published', 'active'], true)
                && ! ((bool) $p->is_hidden);
            $names = $productNameByLocale[$p->id] ?? [];
            $productsOut[] = [
                'id' => $p->id,
                'name' => $this->resolveLocalizedName($names, $p->name),
                // Full locale set so the workstation can serve the item name in
                // the pos-web operator's language (Accept-Language) offline,
                // without re-syncing. Null when a locale has no translation —
                // the workstation falls back to `name`.
                'name_ja' => $names['ja'] ?? null,
                'name_en' => $names['en'] ?? null,
                'name_vi' => $names['vi'] ?? null,
                'description' => $p->description,
                'is_active' => $isActive,
                'product_type_code' => $p->product_type_code !== null
                    ? strtolower((string) $p->product_type_code)
                    : null,
                'image_url' => $imageUrlByProductId[$p->id] ?? null,
            ];
        }

        // Gallery emitted as a flat list keyed by product_id. The
        // workstation puller wipes + re-inserts each row into
        // pos_product_galleries so a removed image disappears the
        // next tick.
        $galleriesOut = [];
        foreach ($galleries as $g) {
            $galleriesOut[] = [
                'id' => $g->id,
                'product_id' => $g->product_id,
                'url' => (string) $g->url,
                'original_name' => $g->original_name,
                'mime_type' => $g->mime_type,
                'sort_order' => $g->sort_order !== null ? (int) $g->sort_order : null,
            ];
        }

        // Per-locale SKU variant names ("Regular" → "レギュラー") from the
        // hand-written product_sku_translations table so the workstation can
        // localize the バリエーション list in the product-options dialog.
        $skuNameByLocale = $this->translationsByLocale(
            'product_sku_translations', 'product_sku_id', $productSkus->pluck('id')->all()
        );

        $skusOut = [];
        foreach ($productSkus as $sku) {
            $defaultPrice = (float) $sku->selling_price;
            $effective = $overrideBySku[$sku->id] ?? $defaultPrice;
            $skuNames = $skuNameByLocale[$sku->id] ?? [];
            $skusOut[] = [
                'id' => $sku->id,
                'product_id' => $sku->product_id,
                'name' => (string) ($sku->name ?? ''),
                'name_ja' => $skuNames['ja'] ?? null,
                'name_en' => $skuNames['en'] ?? null,
                'name_vi' => $skuNames['vi'] ?? null,
                'sku' => (string) ($sku->sku ?? ''),
                'selling_price' => (int) round($effective),
                // The canonical product_skus.selling_price — pos-web
                // shows this as the strikethrough above selling_price
                // when is_price_overridden=true. Always emit (cheap +
                // makes the handler's job pure formatting).
                'default_price' => (int) round($defaultPrice),
                'is_price_overridden' => (bool) ($isOverriddenBySku[$sku->id] ?? false),
                'is_active' => (bool) $sku->is_active,
                'image_url' => $imageUrlBySkuId[$sku->id] ?? null,
                'option_value1_id' => $sku->option_value1_id,
                'option_value2_id' => $sku->option_value2_id,
                'option_value3_id' => $sku->option_value3_id,
            ];
        }

        // ─── Plan-022 emit blocks ───────────────────────────────────────────

        // Per-locale names for topping groups / options / option values so the
        // workstation can serve the product-options dialog (Sauce, Remove
        // ingredients, No onion…) in the operator's language, the same way
        // products already carry name_ja/en/vi. Option VALUES translate `label`.
        $toppingGroupNameByLocale = $this->translationsByLocale(
            'topping_group_translations', 'topping_group_id', $toppingGroupIds
        );
        $optionNameByLocale = $this->translationsByLocale(
            'product_option_translations', 'product_option_id', $optionIds
        );
        $optionValueLabelByLocale = $this->translationsByLocale(
            'product_option_value_translations', 'product_option_value_id', $optionValues->pluck('id')->all(), 'label'
        );

        $productOptionsOut = $productOptions->map(fn ($o) => [
            'id' => $o->id,
            'product_id' => $o->product_id,
            'key' => (string) ($o->key ?? ''),
            'name' => (string) ($o->name ?? ''),
            'name_ja' => $optionNameByLocale[$o->id]['ja'] ?? null,
            'name_en' => $optionNameByLocale[$o->id]['en'] ?? null,
            'name_vi' => $optionNameByLocale[$o->id]['vi'] ?? null,
            'position' => (int) ($o->position ?? 0),
            'is_active' => (bool) $o->is_active,
        ])->all();

        $productOptionValuesOut = $optionValues->map(fn ($v) => [
            'id' => $v->id,
            'option_id' => $v->option_id,
            'value' => (string) ($v->value ?? ''),
            'label' => $v->label,
            'label_ja' => $optionValueLabelByLocale[$v->id]['ja'] ?? null,
            'label_en' => $optionValueLabelByLocale[$v->id]['en'] ?? null,
            'label_vi' => $optionValueLabelByLocale[$v->id]['vi'] ?? null,
            'position' => (int) ($v->position ?? 0),
            'is_active' => (bool) $v->is_active,
        ])->all();

        $toppingGroupsOut = $toppingGroups->map(fn ($g) => [
            'id' => $g->id,
            'name' => (string) ($g->name ?? ''),
            'name_ja' => $toppingGroupNameByLocale[$g->id]['ja'] ?? null,
            'name_en' => $toppingGroupNameByLocale[$g->id]['en'] ?? null,
            'name_vi' => $toppingGroupNameByLocale[$g->id]['vi'] ?? null,
            'selection_type' => (string) ($g->selection_type ?? 'multiple'),
            'modifier_type' => (string) ($g->modifier_type ?? 'add'),
            'price_strategy' => (string) ($g->price_strategy ?? 'flat'),
            'free_quantity' => $g->free_quantity !== null ? (int) $g->free_quantity : null,
            'min_select' => (int) ($g->min_select ?? 0),
            'max_select' => $g->max_select !== null ? (int) $g->max_select : null,
            // Column was dropped in 2026-02-13 alter; pos-web's
            // MenuToppingGroupResource also falls back to 1 via `?? 1`.
            'max_qty_per_item' => 1,
            'sort_order' => (int) ($g->sort_order ?? 0),
            'is_active' => (bool) $g->is_active,
        ])->all();

        $toppingItemsOut = $toppingItems->map(fn ($i) => [
            'id' => $i->id,
            'topping_group_id' => $i->topping_group_id,
            'product_id' => $i->product_id,
            'sort_order' => (int) ($i->sort_order ?? 0),
            'is_default' => (bool) $i->is_default,
        ])->all();

        $toppingItemSkusOut = $itemSkus->map(fn ($s) => [
            'id' => $s->id,
            'topping_group_item_id' => $s->topping_group_item_id,
            'product_sku_id' => $s->product_sku_id, // nullable for the simple-topping fallback row
            'extra_price' => (int) round((float) $s->extra_price),
        ])->all();

        $productToppingPivotOut = $productToppingPivot->map(fn ($r) => [
            'product_id' => $r->product_id,
            'topping_group_id' => $r->topping_group_id,
            'sort_order' => (int) ($r->sort_order ?? 0),
            'min_select_override' => $r->min_select_override !== null ? (int) $r->min_select_override : null,
            'max_select_override' => $r->max_select_override !== null ? (int) $r->max_select_override : null,
        ])->all();

        $productItemOverridesOut = $productItemOverrides->map(fn ($r) => [
            'id' => $r->id,
            'product_id' => $r->product_id,
            'topping_group_item_id' => $r->topping_group_item_id,
            'product_sku_id' => $r->product_sku_id,
            'override_price' => $r->override_price !== null ? (int) round((float) $r->override_price) : null,
            'is_hidden' => (bool) $r->is_hidden,
        ])->all();

        // ─── #1180 floating-section emit blocks ─────────────────────────────

        $floatingSectionNameByLocale = $this->translationsByLocale(
            'floating_section_translations', 'floating_section_id', $floatingSectionIds
        );

        $floatingSectionsOut = $floatingSections->map(fn ($s) => [
            'id' => $s->id,
            'name' => (string) ($s->name ?? ''),
            'name_ja' => $floatingSectionNameByLocale[$s->id]['ja'] ?? null,
            'name_en' => $floatingSectionNameByLocale[$s->id]['en'] ?? null,
            'name_vi' => $floatingSectionNameByLocale[$s->id]['vi'] ?? null,
            // Lower priority = shown first, same convention as menus.sort_order.
            'priority' => (int) ($s->priority ?? 0),
            'is_active' => (bool) $s->is_active,
            // Date bounds ship raw (nullable Y-m-d) — the device evaluates them
            // against the shop clock, which is the only clock it has offline.
            'start_date' => $s->start_date !== null ? (string) $s->start_date : null,
            'end_date' => $s->end_date !== null ? (string) $s->end_date : null,
        ])->all();

        $floatingSchedulesOut = $floatingSchedules->map(fn ($s) => [
            'id' => $s->id,
            'floating_section_id' => $s->floating_section_id,
            // Bitmask, 1 << dayOfWeek with 0 = Sunday — same encoding
            // FloatingSectionPriceResolver matches against on Cloud.
            'days_of_week' => (int) ($s->days_of_week ?? 0),
            'start_time' => (string) $s->start_time,
            'end_time' => (string) $s->end_time,
            'start_date' => $s->start_date !== null ? (string) $s->start_date : null,
            'end_date' => $s->end_date !== null ? (string) $s->end_date : null,
            'is_active' => (bool) $s->is_active,
            'priority' => (int) ($s->priority ?? 0),
        ])->all();

        $floatingProductsOut = $floatingProducts->map(fn ($r) => [
            'id' => $r->id,
            'floating_section_id' => $r->floating_section_id,
            'product_id' => $r->product_id,
            // THE TIER COLLAPSE. Identical expression to CustomerMenuService's
            // `$sectionProduct->tax_type_id ?? $product->tax_type_id`, so the
            // rate a spotlight item is billed at offline is the rate Cloud
            // quotes for it online. Null = inherit; the device's resolver
            // carries on to the branch then brand default, as Cloud's does.
            'tax_type_id' => $r->override_tax_type_id ?? $r->product_tax_type_id,
            'is_active' => (bool) $r->is_active,
            'display_order' => (int) ($r->display_order ?? 0),
        ])->all();

        $floatingProductSkusOut = $floatingProductSkus->map(fn ($r) => [
            'id' => $r->id,
            'floating_section_product_id' => $r->floating_section_product_id,
            'product_sku_id' => $r->product_sku_id,
            // The promo price. Lives here and ONLY here.
            'selling_price' => (int) round((float) $r->selling_price),
            'is_active' => (bool) $r->is_active,
            'is_price_overridden' => (bool) $r->is_price_overridden,
        ])->all();

        $floatingItemOverridesOut = $floatingItemOverrides->map(fn ($r) => [
            'id' => $r->id,
            'floating_section_product_id' => $r->floating_section_product_id,
            'topping_group_id' => $r->topping_group_id,
            'topping_group_item_id' => $r->topping_group_item_id,
            'product_sku_id' => $r->product_sku_id,
            'is_hidden' => (bool) $r->is_hidden,
            'override_price' => $r->override_price !== null ? (int) round((float) $r->override_price) : null,
        ])->all();

        $menuProductOverridesOut = $menuProductOverrides->map(fn ($r) => [
            'id' => $r->id,
            'menu_product_id' => $r->menu_product_id,
            'topping_group_id' => $r->topping_group_id,
            'topping_group_item_id' => $r->topping_group_item_id,
            'product_sku_id' => $r->product_sku_id,
            'is_hidden' => (bool) $r->is_hidden,
            'override_price' => $r->override_price !== null ? (int) round((float) $r->override_price) : null,
        ])->all();

        return [
            'menus' => $menusOut,
            'sections' => $sectionsOut,
            'menu_products' => $menuProductsOut,
            // plan-056 — appended, never folded into `skus`. See the emit block
            // above for why the two arrays cannot be one.
            'menu_product_skus' => $menuProductSkusOut,
            'products' => $productsOut,
            'product_galleries' => $galleriesOut,
            'skus' => $skusOut,
            // Plan-022: variant options + topping groups + 3-tier
            // override tables. Workstation resolves effective_*
            // (select bounds + extra_price) at read time, matching
            // MenuToppingGroupResource + MenuToppingGroupItemResource
            // 1:1.
            'product_options' => $productOptionsOut,
            'product_option_values' => $productOptionValuesOut,
            'topping_groups' => $toppingGroupsOut,
            'topping_group_items' => $toppingItemsOut,
            'topping_group_item_skus' => $toppingItemSkusOut,
            'product_topping_groups' => $productToppingPivotOut,
            'product_topping_item_overrides' => $productItemOverridesOut,
            'menu_product_topping_overrides' => $menuProductOverridesOut,
            // #1180: the spotlight. Products/skus/options/toppings for these
            // rows are already inside the blocks above (the scope was expanded
            // before they ran), so these five arrays only carry what is
            // floating-specific: the section, its window, the membership + its
            // collapsed tax, the promo price, and the topping overrides.
            'floating_sections' => $floatingSectionsOut,
            'floating_section_schedules' => $floatingSchedulesOut,
            'floating_section_products' => $floatingProductsOut,
            'floating_section_product_skus' => $floatingProductSkusOut,
            'floating_section_topping_overrides' => $floatingItemOverridesOut,
        ];
    }

    /**
     * Build a [foreign_id => [locale => value]] map from a *_translations table,
     * skipping blank values — the same shape as $productNameByLocale, reused for
     * topping groups / options / option values / skus so the workstation can
     * mirror per-locale names and serve the operator's language offline.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array<string, string>>
     */
    private function translationsByLocale(string $table, string $foreignKey, array $ids, string $valueColumn = 'name'): array
    {
        $map = [];
        if (empty($ids)) {
            return $map;
        }
        foreach (DB::table($table)
            ->whereIn($foreignKey, $ids)
            ->get([$foreignKey, 'locale', $valueColumn]) as $tr) {
            $value = $tr->{$valueColumn};
            if (is_string($value) && trim($value) !== '') {
                $map[$tr->{$foreignKey}][$tr->locale] = $value;
            }
        }

        return $map;
    }

    /**
     * Resolve a non-empty product name from a {locale: name} map, mirroring
     * Product::localizedName for the raw-query catalog path: request locale →
     * vi → ja → en → any translation → the base column. Returns '' only when
     * the product has no name anywhere (a source data error).
     *
     * @param  array<string, string>  $byLocale
     */
    private function resolveLocalizedName(array $byLocale, ?string $base): string
    {
        $locale = app()->getLocale();

        foreach (array_values(array_unique([$locale, 'vi', 'ja', 'en'])) as $loc) {
            $value = $byLocale[$loc] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        foreach ($byLocale as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return is_string($base) && trim($base) !== '' ? $base : '';
    }

    /**
     * plan-056 — a raw-query datetime as ISO-8601 UTC, or null.
     *
     * These rows come from the query builder, not Eloquent, so `disabled_at`
     * arrives as whatever string the driver hands back ("2026-08-12 05:31:00"
     * on MySQL) with no timezone marker at all. Shipping that verbatim would
     * make the workstation guess, and Go's parser would either fail or read it
     * as local time — a 7- or 9-hour lie on a timestamp the shop reads back as
     * "khi nào tắt". Normalise here, where the DB timezone is known.
     */
    private function isoOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function emptyShape(): array
    {
        return [
            'menus' => [],
            'sections' => [],
            'menu_products' => [],
            'menu_product_skus' => [],
            'products' => [],
            'product_galleries' => [],
            'skus' => [],
            'product_options' => [],
            'product_option_values' => [],
            'topping_groups' => [],
            'topping_group_items' => [],
            'topping_group_item_skus' => [],
            'product_topping_groups' => [],
            'product_topping_item_overrides' => [],
            'menu_product_topping_overrides' => [],
            'floating_sections' => [],
            'floating_section_schedules' => [],
            'floating_section_products' => [],
            'floating_section_product_skus' => [],
            'floating_section_topping_overrides' => [],
        ];
    }
}
