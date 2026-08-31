<?php

namespace App\Services\Customer;

use App\Models\Product;
use App\Models\TaxType;
use App\Services\Order\Contracts\BranchDefaultTaxType;
use App\Services\Tax\Contracts\MenuTaxTypeAnchors;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the consumption-tax type + rate for a single order line
 * (plan-043 §7; single-rate model #1099).
 *
 * Resolution chain (highest priority first):
 *   1. MenuProduct.tax_type_id (menu-item override)
 *   2. MenuMenuSection.tax_type_id (this section IN THIS MENU — #1218)
 *   3. Menu.tax_type_id (the whole menu — #1218)
 *   4. Product.tax_type_id
 *   5. `shop_order_settings.default_tax_type_id` (branch default — read through
 *      the `BranchDefaultTaxType` port, #962)
 *   6. brand's TaxType with is_default = true (NO BrandOrderPolicy)
 *   → if NOTHING resolves (brand with zero tax types): 0% (no type). The
 *     legacy tax_rate fallback was dropped in T6.2.
 *
 * A tax type is a PURE RATE (#1099, 2026-07-26): the resolver never looks at
 * the order type. Consumption context (店内 vs 持ち帰り) is a CATALOG concern —
 * the takeaway menu carries the 8%, so the rate comes from WHICH menu line the
 * customer ordered, not from a rate pair. No special-casing beyond the tier
 * walk (alcohol removed the same day).
 *
 * #1218 put the menu and section tiers ABOVE the product deliberately, by
 * ruling: "the menu always wins; an item set wrongly is a human error, and
 * without a menu value we fall back to the product". So there is NO 非課税/0%
 * exception — a menu-wide 8% does override a product marked tax-exempt. The way
 * to keep one item exempt inside a taxed menu is to give that menu line its own
 * override, which is tier 1 and still wins. Placing these tiers BELOW the
 * product was rejected: once every brand is seeded with the standard types
 * almost every product carries one, which would have made a menu-level setting
 * do nothing at all.
 *
 * Per-instance memoisation keeps branch/brand default lookups to one query
 * each (N+1 guard — callers eager-load `product.taxType`; create a fresh
 * resolver per order operation so the memo can't go stale mid-request).
 *
 * #962 — chỉ tầng 4 còn cầm model Catalog (`Product`, qua chữ ký công khai
 * `resolveForLine`/`resolveRateForDisplay`). Tầng 2 và 3 đọc `menu_menu_sections`
 * và `menus` qua cổng {@see MenuTaxTypeAnchors}, tầng 5 qua
 * {@see BranchDefaultTaxType} — cả ba trả về **id**, còn thứ tự tầng thì ở lại
 * đúng một chỗ là {@see walk()}.
 *
 * ## Cạnh `Pricing → Catalog` này CỐ Ý Ở LẠI (#1597)
 *
 * Nó gỡ được về mặt kỹ thuật: `resolveForLineByIds` / `resolveRateForDisplayByIds`
 * đã tồn tại, đi **đúng `walk()`** ấy, và chỗ gọi production duy nhất còn lại là
 * `Shop\MenuController` — tầng **Composition**, vốn được phép chạm mọi module.
 *
 * Cái sẽ mất khi gỡ mới là vấn đề: `TaxResolutionGoldenParityTest` và
 * `PricingMenuTaxAnchorPortsTest` dùng lối-bằng-model làm **chuẩn tham chiếu**
 * để chứng minh lối-bằng-id tính ra CÙNG một kết quả. Xoá lối cũ là xoá luôn
 * cái chuẩn ấy — từ đó không còn gì bắt được một lần lệch tầng trong `walk()`,
 * và thuế snapshot bất biến lên từng dòng đơn nên một lần lệch là sai vĩnh viễn
 * trên hoá đơn đã phát hành.
 *
 * Đổi một tài sản an toàn thật lấy một con số baseline nhỏ hơn là **gian lận
 * phép đo**, chỉ tinh vi hơn kiểu bỏ type-hint. Cạnh này ở lại cho tới khi có
 * một chuẩn tham chiếu khác — ví dụ bộ golden fixture độc lập không cần model.
 */
class TaxResolver
{
    /** @var array<string, ?TaxType> branch_id → branch default type (or null) */
    private array $branchDefaults = [];

    /** @var array<string, ?TaxType> brand_id → brand default type (or null) */
    private array $brandDefaults = [];

    /** @var array<string, ?TaxType> menu_id → whole-menu type (#1218 tier 3) */
    private array $menuDefaults = [];

    /** @var array<string, ?TaxType> "menu_id|section_id" → pivot type (#1218 tier 2) */
    private array $sectionDefaults = [];

    /** @var array<string, ?TaxType> tax_type_id → type (#962 · 7a-7, the by-id entry point) */
    private array $typesById = [];

    /** @var array<string, true> brand_id → already warned about 0% fallback (log-spam guard) */
    private array $warnedBrands = [];

    /**
     * Resolve the tax type + rate for one line.
     *
     * @param  Product  $product  the line's product (eager-load `taxType` to avoid N+1)
     * @param  TaxType|null  $menuTaxType  MenuProduct override, already resolved (null = none)
     * @param  string|null  $menuId  the menu this line was ordered from (#1218 tier 3)
     * @param  string|null  $menuSectionId  its section within that menu (#1218 tier 2)
     */
    public function resolveForLine(
        Product $product,
        ?TaxType $menuTaxType,
        string $branchId,
        string $brandId,
        ?string $menuId = null,
        ?string $menuSectionId = null,
    ): TaxResolution {
        return $this->resolutionFor(
            $this->resolveType($product, $menuTaxType, $branchId, $brandId, $menuId, $menuSectionId),
            (string) $product->id,
            $branchId,
            $brandId,
        );
    }

    /**
     * #962 · 7a-7 — the SAME resolution, entered by id instead of by model.
     *
     * This exists so Ordering can resolve a line's tax through
     * `App\Services\Order\Contracts\OrderLineTaxBatch` without importing `Product`
     * (Catalog) or `TaxType` (Pricing). It is NOT a second tax engine: it hydrates
     * the two id-shaped tiers and then walks {@see walk()} — the one and only tier
     * chain, shared verbatim with `resolveForLine` above.
     *
     * Hydrating by id is equivalent to reading the relation: `Product::taxType()` is
     * a plain `belongsTo(TaxType::class, 'tax_type_id')` with no scope of its own,
     * and `TaxType` soft-deletes, so a soft-deleted type yields `null` either way
     * and the next tier picks the line up exactly as before.
     *
     * @param  string|null  $menuTaxTypeId  tier 1 — the menu line's own override
     * @param  string|null  $productTaxTypeId  tier 4 — `products.tax_type_id`
     */
    public function resolveForLineByIds(
        string $productId,
        ?string $productTaxTypeId,
        ?string $menuTaxTypeId,
        string $branchId,
        string $brandId,
        ?string $menuId = null,
        ?string $menuSectionId = null,
    ): TaxResolution {
        $type = $this->walk(
            $this->typeById($menuTaxTypeId),
            $this->typeById($productTaxTypeId),
            $branchId,
            $brandId,
            $menuId,
            $menuSectionId,
        );

        return $this->resolutionFor($type, $productId, $branchId, $brandId);
    }

    /**
     * The resolved type turned into a snapshot, plus the "nothing resolved" alarm.
     * Shared by both entry points so the warning cannot drift between them.
     *
     * Nothing resolved (no menu/product override, no branch default, no brand
     * default). plan-043 T6.2 dropped the legacy tax_rate fallback — every brand is
     * seeded with an is_default type (BrandBaselineProvisioner), so this is
     * only reachable for a brand with zero tax types → 0% (no type to snapshot).
     * Audit fix 1.2 (2026-07-14): this used to be SILENT — a brand whose seeding was
     * skipped under-collected tax on every order with no trace. Warn loudly (once
     * per brand per resolver) so ops catch it.
     */
    private function resolutionFor(?TaxType $type, string $productId, string $branchId, string $brandId): TaxResolution
    {
        // Nothing resolved (no menu/product override, no branch default, no
        // brand default). plan-043 T6.2 dropped the legacy tax_rate fallback —
        // every brand gets its standard types from the Platform provisioning
        // entrypoint (UserProvisioner::syncBrands) qua
        // BrandBaselineProvisioner (by design there is NO `Brand::created`
        // hook for tax types — AppServiceProvider:514), so this is only
        // reachable for a brand with zero tax types → 0% (no type to snapshot).
        // Audit fix 1.2 (2026-07-14): this used to be SILENT — a brand whose
        // seeding was skipped under-collected tax on every order with no
        // trace. Warn loudly (once per brand per resolver) so ops catch it.
        if ($type === null) {
            if (! isset($this->warnedBrands[$brandId])) {
                $this->warnedBrands[$brandId] = true;
                Log::warning('TaxResolver: no tax type resolved — line taxed at 0%. Brand has no tax types. Fix: run `php artisan provisioning:reconcile --brand=<slug>` (idempotent; `--dry-run` first to see what is missing). A brand should never reach this state — the baseline runs at Platform sync (UserProvisioner::syncBrands), at branch creation, and in BaselineProvisioningSeeder. By design there is NO `Brand::created` hook for tax types (AppServiceProvider:514).', [
                    'brand_id' => $brandId,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                ]);
            }

            return new TaxResolution(null, 0.0);
        }

        return new TaxResolution($type->id, (float) $type->rate);
    }

    /**
     * Memoised id → type hydration for the two tiers that arrive as ids on the
     * `resolveForLineByIds` path. Memoised like the other tiers because one addItems
     * batch repeats the same handful of type ids across every line.
     */
    private function typeById(?string $taxTypeId): ?TaxType
    {
        if ($taxTypeId === null) {
            return null;
        }

        if (! array_key_exists($taxTypeId, $this->typesById)) {
            $this->typesById[$taxTypeId] = TaxType::query()->find($taxTypeId);
        }

        return $this->typesById[$taxTypeId];
    }

    /**
     * The rate a DISPLAY surface (menu hint) should show for a line, or null
     * when the tier walk resolves nothing.
     *
     * Same four tiers as `resolveForLine`, deliberately reusing them so the menu
     * hint and the invoice can never disagree (#1099 audit follow-up) — before
     * this, `CustomerMenuService` walked only menu→product and returned null for
     * an inheriting item, leaving the client to guess a rate the order endpoint
     * would then contradict.
     *
     * It differs in ONE way: it does not emit the "no tax type resolved" warning.
     * That warning means "a sale is about to under-collect tax" and is scoped to
     * the money path; the menu endpoint is hit on every customer page view, so
     * logging there would bury the real occurrences under display traffic. The
     * null return already carries the same information to the caller.
     */
    public function resolveRateForDisplay(
        Product $product,
        ?TaxType $menuTaxType,
        string $branchId,
        string $brandId,
        ?string $menuId = null,
        ?string $menuSectionId = null,
    ): ?float {
        $type = $this->resolveType($product, $menuTaxType, $branchId, $brandId, $menuId, $menuSectionId);

        return $type === null ? null : (float) $type->rate;
    }

    /**
     * #1596 — the SAME display resolution, entered by id instead of by model.
     *
     * This exists so Catalog can price the customer menu hint through
     * `App\Services\Tax\Contracts\MenuDisplayTaxRateBatch` without importing
     * `Product` (Catalog) or `TaxType` (Pricing). Like `resolveForLineByIds`, it
     * is NOT a second engine: it hydrates the two id-shaped tiers and then walks
     * {@see walk()} — the one and only tier chain.
     *
     * Hydrating by id is equivalent to reading the relation, for exactly the
     * reason spelled out on `resolveForLineByIds`: `Product::taxType()` is a
     * plain `belongsTo` with no scope of its own and `TaxType` soft-deletes, so
     * a soft-deleted type yields `null` either way and the next tier picks the
     * line up unchanged.
     *
     * It keeps the ONE difference `resolveRateForDisplay` documents: no
     * "no tax type resolved" warning. The menu endpoint runs on every customer
     * page view, so logging there would bury the real under-collection events.
     *
     * @param  string|null  $menuTaxTypeId  tier 1 — the menu line's own override
     * @param  string|null  $productTaxTypeId  tier 4 — `products.tax_type_id`
     */
    public function resolveRateForDisplayByIds(
        ?string $menuTaxTypeId,
        ?string $productTaxTypeId,
        string $branchId,
        string $brandId,
        ?string $menuId = null,
        ?string $menuSectionId = null,
    ): ?float {
        $type = $this->walk(
            $this->typeById($menuTaxTypeId),
            $this->typeById($productTaxTypeId),
            $branchId,
            $brandId,
            $menuId,
            $menuSectionId,
        );

        return $type === null ? null : (float) $type->rate;
    }

    /** The six-tier walk itself — the single definition both callers share. */
    private function resolveType(
        Product $product,
        ?TaxType $menuTaxType,
        string $branchId,
        string $brandId,
        ?string $menuId,
        ?string $menuSectionId,
    ): ?TaxType {
        return $this->walk($menuTaxType, $product->taxType, $branchId, $brandId, $menuId, $menuSectionId);
    }

    /**
     * THE tier chain. Every entry point — by model or by id — funnels through here,
     * so the order of tiers has exactly one definition.
     *
     * Extracted from `resolveType` by #962 · 7a-7 with no change to the chain: the
     * only difference is that tier 4 arrives as an argument instead of being read
     * off a `Product`. Do not inline it back; a second copy of this chain is a
     * second tax engine, and plan-043's ruling (#1218: section and whole-menu sit
     * ABOVE the product) would then have two places to disagree.
     */
    private function walk(
        ?TaxType $menuTaxType,
        ?TaxType $productTaxType,
        string $branchId,
        string $brandId,
        ?string $menuId,
        ?string $menuSectionId,
    ): ?TaxType {
        return $menuTaxType
            ?? $this->sectionDefault($menuId, $menuSectionId)
            ?? $this->menuDefault($menuId)
            ?? $productTaxType
            ?? $this->branchDefault($branchId)
            ?? $this->brandDefault($brandId);
    }

    /**
     * Tier 2 — the section's type FOR THIS MENU. It is read off the pivot, not
     * off `menu_sections`, because a section is N:N with menus and is reused
     * heavily; a column on the section itself would leak one menu's rate into
     * every other menu that shows it.
     */
    private function sectionDefault(?string $menuId, ?string $menuSectionId): ?TaxType
    {
        if ($menuId === null || $menuSectionId === null) {
            return null;
        }

        $key = $menuId.'|'.$menuSectionId;

        if (! array_key_exists($key, $this->sectionDefaults)) {
            // #962 — `menu_menu_sections` là bảng của Catalog; hỏi qua cổng rồi tự
            // nạp `TaxType` (model của Pricing) bằng luật của mình, hệt như tầng 5
            // đã làm với `BranchDefaultTaxType`. Cổng trả ID chứ không trả model:
            // xem `MenuTaxTypeAnchors`.
            $this->sectionDefaults[$key] = $this->typeById(
                app(MenuTaxTypeAnchors::class)->taxTypeIdForMenuSection($menuId, $menuSectionId),
            );
        }

        return $this->sectionDefaults[$key];
    }

    /** Tier 3 — one type for the whole menu (the 持ち帰り menu is 8%). */
    private function menuDefault(?string $menuId): ?TaxType
    {
        if ($menuId === null) {
            return null;
        }

        if (! array_key_exists($menuId, $this->menuDefaults)) {
            // #962 — `menus` là bảng của Catalog; cùng cổng, cùng lý do như tầng 2.
            $this->menuDefaults[$menuId] = $this->typeById(
                app(MenuTaxTypeAnchors::class)->taxTypeIdForMenu($menuId),
            );
        }

        return $this->menuDefaults[$menuId];
    }

    /**
     * `array_key_exists`, not `??=`: a resolved-to-NULL default must be cached
     * too. With `??=` the null was indistinguishable from "not looked up yet",
     * so a branch with no default re-ran the query for EVERY line — 2 wasted
     * queries per line on exactly the tenants that resolve furthest down the
     * chain. Caught by the menu endpoint's query-count ceiling.
     */
    private function branchDefault(string $branchId): ?TaxType
    {
        if (! array_key_exists($branchId, $this->branchDefaults)) {
            // #962 — `shop_order_settings` là bảng của Ordering; hỏi qua cổng
            // rồi tự nạp `TaxType` (model của Pricing) bằng luật của mình.
            // Cổng trả về ID chứ không trả về model: xem `BranchDefaultTaxType`.
            $typeId = app(BranchDefaultTaxType::class)->defaultTaxTypeIdForBranch($branchId);

            $this->branchDefaults[$branchId] = $typeId === null
                ? null
                : TaxType::query()->find($typeId);
        }

        return $this->branchDefaults[$branchId];
    }

    private function brandDefault(string $brandId): ?TaxType
    {
        if (! array_key_exists($brandId, $this->brandDefaults)) {
            $this->brandDefaults[$brandId] = TaxType::query()
                ->where('brand_id', $brandId)
                ->where('is_default', true)
                ->first();
        }

        return $this->brandDefaults[$brandId];
    }
}
