<?php

namespace App\Services\Catalog;

use App\Jobs\Catalog\RebuildCatalogRevisionJob;
use App\Models\CatalogRevision;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Services\Catalog\Contracts\CatalogRevisionMarker;
use App\Services\Topping\ToppingPricingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-branch catalog versioning for offline-order evidence (#1092/#1095).
 *
 * A workstation that sells offline stamps the revision it was looking at onto
 * the order's evidence. When that order syncs UP days later, the verifier
 * (#1096) re-prices it against THIS revision's snapshot — not today's menu —
 * because the customer already paid the old price.
 *
 * The snapshot is deliberately narrow: only what changes an offline charge
 * (which SKU, at what price, under which tax type). Names, images, sort order
 * and translations are excluded, so cosmetic edits never bump the revision and
 * never shorten an offline device's grace window.
 */
class CatalogRevisionService implements CatalogRevisionMarker
{
    /**
     * Snapshot shape version. v1 was lines-only (a flat menu_sku => price map);
     * v2 (#1114) nests lines under `lines` and adds the topping pricing inputs;
     * v3 (#1192) adds `topping_price_overrides`, the per-menu-line SHOP tier
     * the product-keyed `topping_prices` map structurally cannot express.
     * The verifier reads all three — an offline order naming a v1 revision is
     * still priced for its menu lines, it just cannot carry toppings.
     */
    public const SNAPSHOT_VERSION = 4;

    /**
     * Branches whose price map changed in the current transaction, flushed
     * once on commit.
     *
     * A big menu mutation (syncLayout, cloneToBranch) touches dozens of rows;
     * bumping per row would recompute the snapshot dozens of times and — worse
     * — could publish a revision mid-mutation that an offline device pulls
     * while the menu is half-written. Marking dirty and flushing after COMMIT
     * gives exactly one revision per logical menu change, computed from the
     * committed state.
     *
     * @var array<string, true>
     */
    private array $dirtyBranchIds = [];

    /**
     * Register a branch as needing a revision check. Safe to call many times
     * per transaction — the flush is idempotent and the no-op guard
     * (BR-CR02) means an unchanged price map still produces no new revision.
     */
    public function markDirty(?string $branchId): void
    {
        if ($branchId === null || $branchId === '') {
            return;
        }

        $this->dirtyBranchIds[$branchId] = true;

        // Registered per mark rather than once-per-transaction on purpose: a
        // ROLLED BACK transaction discards its afterCommit callbacks, and a
        // "already scheduled" flag would then stay stuck true and swallow the
        // next real mutation's flush. Extra callbacks are free — flushDirty
        // drains the set, so all but the first are no-ops.
        DB::afterCommit(fn () => $this->flushDirty());
    }

    /**
     * #1661 — mark every branch of a BRAND.
     *
     * Tầng 6 (loại thuế mặc định của thương hiệu) và **thuế suất** của bất kỳ
     * loại thuế nào đều là giá trị cấp thương hiệu: đổi chúng là đổi số tiền mọi
     * chi nhánh của thương hiệu đó thu.
     *
     * Duyệt theo `menus.branch_id` chứ không theo `branches` — chi nhánh chưa có
     * menu nào thì chưa có catalog để đánh phiên bản, đúng như
     * {@see branchIdsCarryingProduct}.
     */
    public function markBrandDirty(?string $brandId): void
    {
        if ($brandId === null || $brandId === '') {
            return;
        }

        foreach ($this->branchIdsOfBrand($brandId) as $branchId) {
            $this->markDirty($branchId);
        }
    }

    /** Mark every branch whose active menus carry the product (price inheritance). */
    public function markProductDirty(?string $productId): void
    {
        if ($productId === null || $productId === '') {
            return;
        }

        foreach ($this->branchIdsCarryingProduct($productId) as $branchId) {
            $this->markDirty($branchId);
        }
    }

    /**
     * Dispatch ONE background rebuild per dirty branch (#1174, option C).
     *
     * The set is branch-unique by construction ($dirtyBranchIds is keyed by
     * branch id, so markProductDirty re-adding the same branch for nine
     * products still yields one entry), and the heavy buildSnapshot() work
     * moved into RebuildCatalogRevisionJob — the HTTP request does ZERO
     * snapshot building inline. Runs post-commit (markDirty registers it via
     * DB::afterCommit), so the job can never observe a half-written menu; on
     * the sync queue driver (tests) the job still executes inline here,
     * preserving the old read-your-own-write behaviour for suites.
     *
     * @return array<int, string> branch ids a rebuild job was dispatched for
     */
    public function flushDirty(): array
    {
        $branchIds = array_map(strval(...), array_keys($this->dirtyBranchIds));
        $this->dirtyBranchIds = [];

        foreach ($branchIds as $branchId) {
            RebuildCatalogRevisionJob::dispatch($branchId);
        }

        return $branchIds;
    }

    /**
     * Bump the branch's revision if (and only if) its price map changed.
     *
     * Returns the current revision either way, so callers can treat it as
     * "give me the authoritative revision after my mutation" (BR-CR02).
     */
    public function bumpFor(string $branchId): ?CatalogRevision
    {
        $snapshot = $this->buildSnapshot($branchId);
        $hash = $this->hashSnapshot($snapshot);

        return DB::transaction(function () use ($branchId, $snapshot, $hash): ?CatalogRevision {
            // Lock the branch's tail so two concurrent menu edits cannot mint
            // the same revision number (the [branch_id, revision] unique index
            // is the DB backstop; this avoids the 500 in the first place).
            $latest = CatalogRevision::query()
                ->where('branch_id', $branchId)
                ->orderByDesc('revision')
                ->lockForUpdate()
                ->first();

            // BR-CR02 — a mutation that leaves the price map byte-identical is
            // not a new catalog. Cosmetic edits must not invalidate anything.
            if ($latest !== null && $latest->snapshot_hash === $hash) {
                return $latest;
            }

            // A branch with no priced line yet has no catalog to version. Test
            // the LINES map, not the envelope — a v2 snapshot always carries
            // its version key, so `$snapshot === []` is never true. (An EMPTIED
            // catalog is different: that's a real change from a previous
            // revision and does get recorded.)
            if ($latest === null && ($snapshot['lines'] ?? []) === []) {
                return null;
            }

            $organizationId = Menu::query()->where('branch_id', $branchId)->value('organization_id');

            return CatalogRevision::create([
                'branch_id' => $branchId,
                'organization_id' => $organizationId,
                'revision' => ($latest?->revision ?? 0) + 1,
                'snapshot_hash' => $hash,
                'snapshot' => $snapshot,
            ]);
        });
    }

    /**
     * Bump every branch whose active menus carry the given product — a
     * product-level price or tax edit changes the effective offline charge on
     * each of those branches (a menu row inherits the SKU's selling_price
     * unless it overrides it).
     *
     * @return array<string, ?CatalogRevision> branch_id → resulting revision
     */
    public function bumpForProduct(string $productId): array
    {
        $out = [];
        foreach ($this->branchIdsCarryingProduct($productId) as $branchId) {
            $out[$branchId] = $this->bumpFor($branchId);
        }

        return $out;
    }

    /**
     * Mark every branch whose menu carries a product that ATTACHES this topping
     * group (#1114). A group's strategy, its items, and their prices all change
     * what an offline device would charge, so they must version the catalog too
     * — a topping edit that skipped the bump would leave the snapshot lying
     * about a price the device is about to sell at.
     */
    public function markToppingGroupDirty(?string $toppingGroupId): void
    {
        if ($toppingGroupId === null || $toppingGroupId === '') {
            return;
        }

        $productIds = DB::table('product_topping_groups')
            ->where('topping_group_id', $toppingGroupId)
            ->distinct()
            ->pluck('product_id');

        foreach ($productIds as $productId) {
            $this->markProductDirty((string) $productId);
        }
    }

    /** @return array<int, string> */
    private function branchIdsOfBrand(string $brandId): array
    {
        return Menu::query()
            ->whereNotNull('branch_id')
            ->where('brand_id', $brandId)
            ->distinct()
            ->pluck('branch_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /** @return array<int, string> */
    private function branchIdsCarryingProduct(string $productId): array
    {
        return Menu::query()
            ->whereNotNull('branch_id')
            ->whereIn('id', MenuProduct::query()->where('product_id', $productId)->select('menu_id'))
            ->distinct()
            ->pluck('branch_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /** The revision an offline device should stamp onto new orders. */
    public function currentFor(string $branchId): ?CatalogRevision
    {
        return CatalogRevision::query()
            ->where('branch_id', $branchId)
            ->orderByDesc('revision')
            ->first();
    }

    /** Look up one historical revision for verification (#1096). */
    public function find(string $branchId, int $revision): ?CatalogRevision
    {
        return CatalogRevision::query()
            ->where('branch_id', $branchId)
            ->where('revision', $revision)
            ->first();
    }

    /**
     * The price-relevant projection of a branch's catalog.
     *
     * SNAPSHOT v2 (#1114) adds the topping pricing inputs so an offline order
     * with toppings can be re-priced historically instead of being refused;
     * v3 (#1192) adds the per-menu-line SHOP override tier; **v4 (#1661) adds
     * the tax tier inputs** — see {@see buildTaxTierSnapshot}. Shape:
     *   v                       → 4
     *   lines                   → menu_product_sku_id => [sku, price, tax]
     *   tax_tiers               → [sections, menus, products, branch, brand, rates]
     *   topping_items           → topping_group_item_id => [group]
     *   topping_groups          => group_id => [strategy, free]
     *   topping_prices          => "parentProductId|itemId|toppingSkuId" => price
     *   topping_price_overrides => "menuProductId|itemId|toppingSkuId" => price
     *
     * The override map is consulted FIRST by the verifier and carries only the
     * menu lines a shop actually overrode, so an untouched branch's snapshot is
     * byte-identical to its v2 shape apart from the version key.
     *
     * Topping prices are RESOLVED at snapshot time (per-product override →
     * per-SKU extra_price → NULL-SKU fallback), so a historical replay never
     * has to walk today's override tables — which is the whole point: those
     * tables move, and the customer already paid.
     *
     * Deterministic by construction: every map is ordered by its key, values
     * are scalars, and prices are decimal STRINGS so no float formatting can
     * drift between the writer and the hash.
     *
     * @return array<string, mixed>
     */
    public function buildSnapshot(string $branchId): array
    {
        $lines = $this->buildLineSnapshot($branchId);
        $toppings = $this->buildToppingSnapshot($branchId);

        return [
            'v' => self::SNAPSHOT_VERSION,
            'lines' => $lines,
            ...$toppings,
            'tax_tiers' => $this->buildTaxTierSnapshot($branchId),
        ];
    }

    /**
     * #1661 — các ĐẦU VÀO của chuỗi tầng thuế, ghi nguyên trạng.
     *
     * ## Vì sao chúng phải nằm trong snapshot
     *
     * `catalog_revisions.revision` **là số phiên bản của feed menu**
     * (`SyncManifestService` trả `'rev-'.$revision`, #1175 dùng nó cho
     * conditional GET). Và feed gộp bốn tầng thuế vào MỘT cột
     * `menu_items.tax_type_id` (`CustomerMenuService`), rồi chở thêm
     * `effective_tax_rate` đi qua cả sáu tầng.
     *
     * Trước #1661 snapshot chỉ mang tầng 1, nên đổi tầng 2-6 để lại hash Y HỆT.
     * `bumpFor()` chỉ mint khi hash đổi (BR-CR02) ⇒ không có bản mới ⇒ workstation
     * nhận **304** và tiếp tục in theo thuế suất cũ trong khi Cloud ghi sổ theo
     * thuế suất mới. `markDirty` một mình không cứu được: nó vẫn chạy ở tầng 3
     * (`Menu` vốn đã được observe) mà vẫn không mint gì.
     *
     * ## Ghi ĐẦU VÀO, KHÔNG phân giải
     *
     * Ở đây cố ý **không** viết `COALESCE(mp, mms, m, p, sos, brand)`. Thứ tự sáu
     * tầng là tài sản của `TaxResolver::walk()` — *"the one and only tier walk"* —
     * và dựng lại nó bằng SQL là tạo bộ đi tầng THỨ HAI, đúng thứ mà #962 xếp vào
     * loại nợ đắt nhất (`TaxResolver → Product` được giữ lại chính vì lý do này).
     * Hash chỉ cần biết đầu vào có đổi hay không; ai phân giải thì vẫn là
     * `TaxResolver`.
     *
     * `rates` có mặt vì đổi **con số** của một loại thuế cũng đổi số tiền khách
     * trả, dù không tầng nào trỏ khác đi.
     *
     * Mọi map đều sắp theo khoá, giá trị là vô hướng, thuế suất là CHUỖI thập
     * phân — cùng luật tất định với `lines`.
     *
     * @return array<string, mixed>
     */
    private function buildTaxTierSnapshot(string $branchId): array
    {
        $menuIds = Menu::query()
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('tax_type_id', 'id');

        $menus = [];
        foreach ($menuIds as $menuId => $taxTypeId) {
            $menus[(string) $menuId] = $taxTypeId === null ? null : (string) $taxTypeId;
        }

        // Tầng 2 — thuế của section TRONG MENU NÀY. Giá trị sống trên pivot chứ
        // không trên `menu_sections`: một section được dùng lại ở nhiều menu, nên
        // đặt trên section sẽ theo nó sang mọi menu khác (#1218).
        $sections = [];
        if ($menus !== []) {
            $rows = DB::table('menu_menu_sections')
                ->whereIn('menu_id', array_keys($menus))
                ->orderBy('menu_id')
                ->orderBy('menu_section_id')
                ->get(['menu_id', 'menu_section_id', 'tax_type_id']);

            foreach ($rows as $row) {
                $sections[$row->menu_id.'|'.$row->menu_section_id] = $row->tax_type_id === null
                    ? null
                    : (string) $row->tax_type_id;
            }
        }

        // Tầng 4 — chỉ những sản phẩm THỰC SỰ bán được trên chi nhánh này.
        $products = [];
        if ($menus !== []) {
            $rows = DB::table('products as p')
                ->join('menu_products as mp', 'mp.product_id', '=', 'p.id')
                ->whereIn('mp.menu_id', array_keys($menus))
                ->whereNull('mp.deleted_at')
                ->whereNull('p.deleted_at')
                ->distinct()
                ->orderBy('p.id')
                ->get(['p.id', 'p.tax_type_id']);

            foreach ($rows as $row) {
                $products[(string) $row->id] = $row->tax_type_id === null ? null : (string) $row->tax_type_id;
            }
        }

        $brandId = Menu::query()->where('branch_id', $branchId)->value('brand_id');

        $rates = [];
        if ($brandId !== null) {
            $rows = DB::table('tax_types')
                ->where('brand_id', $brandId)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get(['id', 'rate', 'is_default', 'is_active']);

            foreach ($rows as $row) {
                $rates[(string) $row->id] = number_format((float) $row->rate, 4, '.', '');
            }
        }

        $branchDefault = DB::table('shop_order_settings')
            ->where('branch_id', $branchId)
            ->value('default_tax_type_id');

        $brandDefault = $brandId === null ? null : DB::table('tax_types')
            ->where('brand_id', $brandId)
            ->where('is_default', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');

        return [
            'sections' => $sections,
            'menus' => $menus,
            'products' => $products,
            'branch' => $branchDefault === null ? null : (string) $branchDefault,
            'brand' => $brandDefault === null ? null : (string) $brandDefault,
            'rates' => $rates,
        ];
    }

    /**
     * @return array<string, array{sku: string, price: string, tax: ?string}>
     */
    private function buildLineSnapshot(string $branchId): array
    {
        $rows = DB::table('menu_product_skus as mps')
            ->join('menu_products as mp', 'mp.id', '=', 'mps.menu_product_id')
            ->join('menus as m', 'm.id', '=', 'mp.menu_id')
            ->join('product_skus as ps', 'ps.id', '=', 'mps.product_sku_id')
            ->where('m.branch_id', $branchId)
            ->whereNull('m.deleted_at')
            ->whereNull('mp.deleted_at')
            ->whereNull('mps.deleted_at')
            ->where('mps.is_active', true)
            ->where('mp.is_active', true)
            ->orderBy('mps.id')
            ->get([
                'mps.id as menu_product_sku_id',
                'mps.product_sku_id',
                'mps.selling_price as menu_price',
                'mps.is_price_overridden',
                'ps.selling_price as sku_price',
                'ps.product_id',
                'mp.tax_type_id as menu_tax_type_id',
            ]);

        $snapshot = [];
        foreach ($rows as $row) {
            $price = $row->is_price_overridden ? $row->menu_price : $row->sku_price;

            $snapshot[(string) $row->menu_product_sku_id] = [
                'sku' => (string) $row->product_sku_id,
                'price' => number_format((float) $price, 2, '.', ''),
                'tax' => $row->menu_tax_type_id === null ? null : (string) $row->menu_tax_type_id,
            ];
        }

        return $snapshot;
    }

    /**
     * Topping pricing inputs for every product sellable on this branch (#1114).
     *
     * @return array{topping_items: array<string, array{group: string}>, topping_groups: array<string, array{strategy: string, free: int}>, topping_prices: array<string, string>, topping_price_overrides: array<string, string>}
     */
    private function buildToppingSnapshot(string $branchId): array
    {
        // Parent products sellable on the branch's active menus.
        $parentProductIds = DB::table('menu_products as mp')
            ->join('menus as m', 'm.id', '=', 'mp.menu_id')
            ->where('m.branch_id', $branchId)
            ->whereNull('m.deleted_at')
            ->whereNull('mp.deleted_at')
            ->where('mp.is_active', true)
            ->distinct()
            ->pluck('mp.product_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($parentProductIds === []) {
            return ['topping_items' => [], 'topping_groups' => [], 'topping_prices' => [], 'topping_price_overrides' => []];
        }

        // #1192 — the menu lines this branch overrode topping prices on (tier
        // 1). Product-keyed prices structurally cannot carry this tier: two
        // branches selling the same product resolve DIFFERENT topping prices,
        // and the offline snapshot used to record only the HQ answer. The POS
        // (Go) and the online pricer both apply tier 1, so an offline sale at
        // an overriding shop was re-priced low and REJECTED as tampered.
        //
        // Only overriding menu lines are walked, so a branch that never used
        // the feature pays one extra query and stores an empty map.
        $overriddenMenuProducts = DB::table('menu_product_topping_item_overrides as o')
            ->join('menu_products as mp', 'mp.id', '=', 'o.menu_product_id')
            ->join('menus as m', 'm.id', '=', 'mp.menu_id')
            ->where('m.branch_id', $branchId)
            ->whereNull('m.deleted_at')
            ->whereNull('mp.deleted_at')
            ->where('mp.is_active', true)
            ->distinct()
            ->orderBy('mp.id')
            ->get(['mp.id as menu_product_id', 'mp.product_id'])
            ->map(fn ($row): array => [
                'menu_product_id' => (string) $row->menu_product_id,
                'product_id' => (string) $row->product_id,
            ])
            ->all();

        // (parent product → attached group) pairs, then the items in those
        // groups. Inactive groups are excluded: the live pricer refuses them,
        // so recording their prices would imply a sale that cannot happen.
        $attachments = DB::table('product_topping_groups as ptg')
            ->join('topping_groups as tg', 'tg.id', '=', 'ptg.topping_group_id')
            ->whereIn('ptg.product_id', $parentProductIds)
            ->whereNull('tg.deleted_at')
            ->where('tg.is_active', true)
            ->orderBy('ptg.product_id')
            ->orderBy('ptg.topping_group_id')
            ->get(['ptg.product_id', 'ptg.topping_group_id', 'tg.price_strategy', 'tg.free_quantity']);

        $groups = [];
        $groupIds = [];
        foreach ($attachments as $row) {
            $groupId = (string) $row->topping_group_id;
            $groupIds[$groupId] = true;
            $groups[$groupId] = [
                'strategy' => (string) $row->price_strategy,
                'free' => (int) ($row->free_quantity ?? 0),
            ];
        }
        ksort($groups, SORT_STRING);
        $groupIds = array_keys($groupIds);

        // Items of those groups + the concrete SKUs a customer can pick.
        $items = DB::table('topping_group_items as tgi')
            ->join('product_skus as tps', 'tps.product_id', '=', 'tgi.product_id')
            ->whereIn('tgi.topping_group_id', $groupIds)
            ->whereNull('tgi.deleted_at')
            ->where('tps.is_active', true)
            ->orderBy('tgi.id')
            ->orderBy('tps.id')
            ->get(['tgi.id as item_id', 'tgi.topping_group_id', 'tps.id as topping_sku_id']);

        $toppingItems = [];
        $prices = [];
        $overrides = [];
        $pricer = app(ToppingPricingService::class);

        foreach ($items as $row) {
            $itemId = (string) $row->item_id;
            $groupId = (string) $row->topping_group_id;
            $toppingSkuId = (string) $row->topping_sku_id;
            $toppingItems[$itemId] = ['group' => $groupId];

            // The resolved price depends on the PARENT product (tier-1
            // override is scoped per product × group × item × sku), so every
            // reachable parent gets its own entry.
            foreach ($parentProductIds as $parentProductId) {
                if (! $this->productHasGroup($attachments, $parentProductId, $groupId)) {
                    continue;
                }

                try {
                    $price = $pricer->resolveSnapshotPrice($itemId, $toppingSkuId, $parentProductId, $groupId);
                } catch (\Throwable) {
                    // No price row → this combination is not sellable. Omitting
                    // it means an offline order naming it is refused rather
                    // than priced at a guessed zero.
                    continue;
                }

                $prices[$parentProductId.'|'.$itemId.'|'.$toppingSkuId] = number_format($price, 2, '.', '');
            }

            // Tier 1, resolved through the SAME pricer the live channels use —
            // exact-SKU row beats the wildcard row, a hidden or price-less row
            // falls through to the HQ tiers. Recording the resolved answer (not
            // the override row) keeps the verifier a pure map lookup.
            foreach ($overriddenMenuProducts as $menuProduct) {
                if (! $this->productHasGroup($attachments, $menuProduct['product_id'], $groupId)) {
                    continue;
                }

                try {
                    $price = $pricer->resolveSnapshotPrice(
                        $itemId,
                        $toppingSkuId,
                        $menuProduct['product_id'],
                        $groupId,
                        $menuProduct['menu_product_id'],
                    );
                } catch (\Throwable) {
                    continue;
                }

                $overrides[$menuProduct['menu_product_id'].'|'.$itemId.'|'.$toppingSkuId] = number_format($price, 2, '.', '');
            }
        }

        ksort($toppingItems, SORT_STRING);
        ksort($prices, SORT_STRING);
        ksort($overrides, SORT_STRING);

        return [
            'topping_items' => $toppingItems,
            'topping_groups' => $groups,
            'topping_prices' => $prices,
            'topping_price_overrides' => $overrides,
        ];
    }

    /** @param  Collection<int, object>  $attachments */
    private function productHasGroup($attachments, string $productId, string $groupId): bool
    {
        foreach ($attachments as $row) {
            if ((string) $row->product_id === $productId && (string) $row->topping_group_id === $groupId) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $snapshot */
    public function hashSnapshot(array $snapshot): string
    {
        return hash('sha256', json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
