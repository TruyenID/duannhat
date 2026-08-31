<?php

namespace App\Services\Topping\Internal;

use App\Models\ProductSku;
use App\Models\ToppingGroupItem;
use App\Services\Order\Contracts\OrderToppingSelectionPricing;
use App\Services\Order\Contracts\PricedToppingSelection;
use App\Services\Topping\Contracts\ToppingLinePricing;
use Illuminate\Validation\ValidationException;

/**
 * Topping validation + pricing for one order line (plan-047 T2.12, #1090).
 *
 * Extracted VERBATIM from WritesCustomerOrders::validateAndPriceToppings and
 * its two helpers so the legacy addItems path and the typed pricing resolver
 * share ONE implementation. The trait now delegates here — any rule change
 * lands in both engines at once, which is what keeps the typed/legacy parity
 * witness meaningful.
 *
 * Behaviour owned here, in shop terms:
 *  - mandatory groups the customer skipped are auto-filled with the flagged
 *    defaults (combos fall back to first-by-sort so a bundle always orders)
 *  - min/max_select count distinct topping TYPES, max_qty_per_item bounds each
 *  - every selection's unit price snapshots through ToppingPricingService,
 *    honouring per-product override prices
 *  - free_up_to_n / group strategies discount at LINE level; rows keep full price
 *
 * ## #962 · 7a-8 — file này ĐÃ CHUYỂN từ `App\Services\Order\Internal`
 *
 * Không một dòng luật nào ở đây là luật của ĐƠN HÀNG: `product_topping_groups` và
 * override trên pivot của nó, `is_default`, `sort_order`, `price_strategy`,
 * `free_quantity`, mã `combo` — toàn bộ là bảng của Catalog. Nó nằm trong Ordering
 * là di sản của lần tách khỏi trait, và giữ nguyên chỗ đó thì Ordering buộc phải
 * cầm `ProductSku` + `ToppingGroupItem` vĩnh viễn.
 *
 * Chỗ gọi giờ đi qua {@see OrderToppingSelectionPricing} và truyền **id**. Thân hàm
 * bên dưới KHÔNG đổi một biểu thức tiền nào — chỉ thêm bước nạp `ProductSku` ở đầu
 * (thứ mà chỗ gọi từng nạp hộ) và đổi mảng trả về thành
 * {@see PricedToppingSelection}.
 */
final class ToppingSelectionPricer implements OrderToppingSelectionPricing
{
    public function __construct(
        private readonly ToppingLinePricing $toppingPricing,
    ) {}

    /**
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int, note?: string|null}>  $toppings
     */
    public function priceForSku(
        string $productSkuId,
        array $toppings,
        ?string $menuProductId = null,
        ?string $floatingSectionProductId = null,
    ): PricedToppingSelection {
        // Chỗ gọi cũ truyền một `ProductSku` đã nạp sẵn, nên nhánh "không có SKU"
        // trước đây là không thể tới. Vẫn giữ nó là phòng thủ, và dùng cùng hình
        // dạng lỗi với `parent_product_missing` bên dưới thay vì ném 404: tới được
        // đây nghĩa là chỗ gọi đã kiểm SKU rồi mà nó biến mất giữa chừng.
        $sku = ProductSku::query()->with('product')->find($productSkuId);
        if ($sku === null) {
            throw ValidationException::withMessages([
                'items.product_sku_id' => "product_sku_missing: {$productSkuId}",
            ]);
        }

        // Auto-fill defaults for mandatory groups the client omitted entirely.
        // For each ProductToppingGroup attachment with effective min_select ≥ 1
        // that has no entry in $toppings, pull items flagged is_default = true
        // (up to min_select) and inject them as quantity 1. Real per-group
        // count/max validation runs after, so explicit partial selections
        // (e.g. picked 1 of 2 required) still raise toppings_below_min.
        $toppings = $this->autoFillDefaults($sku, $toppings);

        if ($toppings === []) {
            // Optional groups absent → 0 subtotal. Mandatory groups with no
            // selections AND no default items raise toppings_below_min here.
            $this->assertNoMandatoryGroupsMissing($sku, []);

            return new PricedToppingSelection(0.0, []);
        }

        // Eager-load every ToppingGroupItem referenced by selections, with
        // its parent group + the per-product pivot, so we can validate the
        // attachment + compute effective bounds in one round trip.
        $itemIds = collect($toppings)->pluck('topping_group_item_id')->unique()->all();
        $items = ToppingGroupItem::whereIn('id', $itemIds)->with('toppingGroup')->get()->keyBy('id');

        // toppingGroupItem soft-delete catches "removed" rows; for the
        // is_active=false check we lean on the parent group's flag (items
        // do not carry is_active in this schema).
        foreach ($itemIds as $id) {
            if (! $items->has($id)) {
                throw ValidationException::withMessages([
                    'items.toppings.topping_group_item_id' => "topping_item_inactive: {$id}",
                ]);
            }
            $item = $items->get($id);
            if (! $item->toppingGroup || ! $item->toppingGroup->is_active) {
                throw ValidationException::withMessages([
                    'items.toppings.topping_group_item_id' => "topping_item_inactive: {$id}",
                ]);
            }
        }

        // Resolve effective_min/max_select via the per-product pivot (if any).
        // Caller passes ProductSku → product_id → look up the override in
        // product_topping_groups.
        $product = $sku->product()->with(['toppingGroups' => fn ($q) => $q->wherePivot('product_id', $sku->product_id)])->first();
        if ($product === null) {
            // Defensive — should never happen because $sku is loaded above.
            throw ValidationException::withMessages([
                'items.product_sku_id' => 'parent_product_missing',
            ]);
        }

        $attachedGroupIds = $product->toppingGroups->pluck('id')->all();

        // Group selections by topping_group_id for per-group rules.
        $byGroup = [];
        foreach ($toppings as $selection) {
            $item = $items->get($selection['topping_group_item_id']);
            $groupId = $item->topping_group_id;
            if (! in_array($groupId, $attachedGroupIds, true)) {
                throw ValidationException::withMessages([
                    'items.toppings.topping_group_item_id' => "topping_group_not_attached: group {$groupId} is not attached to product {$sku->product_id}",
                ]);
            }
            $byGroup[$groupId][] = $selection;
        }

        // Build a lookup for effective bounds via pivot override.
        $effectiveBounds = [];
        foreach ($product->toppingGroups as $group) {
            $effectiveBounds[$group->id] = [
                'group' => $group,
                'min' => (int) ($group->pivot->min_select_override ?? $group->min_select),
                'max' => $group->pivot->max_select_override ?? $group->max_select,
                // Column dropped in migration 2000_02_13; YAML schema notes
                // Phase 1 defaults to 1 per selection. Match CustomerMenuService
                // so the order-side bound stays in lock-step.
                'max_qty_per_item' => (int) ($group->max_qty_per_item ?? 1),
            ];
        }

        // Per-group constraints — min/max_select count distinct topping TYPES
        // (unique items), never the quantity sum; max_qty_per_item bounds each
        // item's quantity orthogonally.
        $totalSubtotal = 0.0;
        $rows = [];
        foreach ($byGroup as $groupId => $groupSelections) {
            $bound = $effectiveBounds[$groupId];
            $count = count($groupSelections);
            if ($count < $bound['min']) {
                throw ValidationException::withMessages([
                    'items.toppings' => "toppings_below_min: group {$groupId} requires at least {$bound['min']} selections, got {$count}",
                ]);
            }
            if ($bound['max'] !== null && $count > $bound['max']) {
                throw ValidationException::withMessages([
                    'items.toppings' => "toppings_above_max: group {$groupId} allows at most {$bound['max']} selections, got {$count}",
                ]);
            }
            foreach ($groupSelections as $selection) {
                if ((int) $selection['quantity'] > $bound['max_qty_per_item']) {
                    throw ValidationException::withMessages([
                        'items.toppings.quantity' => "topping_qty_above_max: max_qty_per_item={$bound['max_qty_per_item']}",
                    ]);
                }
            }

            // Resolve unit_price snapshot for each selection — falls back to
            // the NULL row (simple topping). Throws topping_item_no_price
            // when neither match exists.
            $resolved = [];
            foreach ($groupSelections as $selection) {
                $unitPrice = $this->toppingPricing->resolveSnapshotPrice(
                    $selection['topping_group_item_id'],
                    $selection['product_sku_id'],
                    // Parent product + group context so per-product
                    // override_price (product_topping_group_item_overrides)
                    // is honoured at pricing — plan-013 Phase 2 Extensions.
                    (string) $sku->product_id,
                    (string) $groupId,
                    // Menu line context so the SHOP tier
                    // (menu_product_topping_item_overrides) is honoured too,
                    // matching the workstation resolver. Null on off-menu /
                    // offline lines → shop tier skipped.
                    $menuProductId,
                    // #1180 — the floating-section twin of that tier. The
                    // customer menu already PRICES the spotlight's toppings
                    // from this owner; without it here the guest was shown the
                    // shop's promo topping price and charged the HQ one.
                    $floatingSectionProductId,
                );
                $resolved[] = [
                    'topping_group_item_id' => (string) $selection['topping_group_item_id'],
                    'product_sku_id' => (string) $selection['product_sku_id'],
                    'quantity' => (int) $selection['quantity'],
                    'unit_price' => $unitPrice,
                    'note' => $selection['note'] ?? null,
                ];
            }

            // Run group through ToppingPricingService for per-group strategy.
            // #1597 — truyền CẤU HÌNH nhóm (chiến lược + số miễn phí) thay vì
            // model: đó là toàn bộ những gì `priceLine` đọc.
            $groupResult = $this->toppingPricing->priceLine(
                $resolved,
                $bound['group']->price_strategy,
                (int) ($bound['group']->free_quantity ?? 0),
            );
            $totalSubtotal += $groupResult['topping_subtotal'];

            // Persist each selection at full unit_price (the discount lives
            // at line level — DESIGN.md Decision 6). #2619 — each row carries
            // HOW MANY of its units free_up_to_n waived (0 under flat), so
            // the money that left topping_subtotal is attributable per row:
            // Σ(unit_price × quantity) − subtotal == Σ(waived_quantity × unit_price).
            foreach ($resolved as $k => $row) {
                $row['waived_quantity'] = $groupResult['waived_by_selection'][$k];
                $rows[] = $row;
            }
        }

        // Catch mandatory groups that the customer omitted entirely.
        $this->assertNoMandatoryGroupsMissing($sku, array_keys($byGroup));

        return new PricedToppingSelection(round($totalSubtotal, 2), $rows);
    }

    /**
     * Auto-fill default selections for mandatory topping groups the customer
     * omitted entirely. Combos with no flagged defaults fall back to the first
     * items by sort_order so the bundle always orders successfully.
     *
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int, note?: string|null}>  $toppings
     * @return array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int, note?: string|null}>
     */
    private function autoFillDefaults(ProductSku $sku, array $toppings): array
    {
        $product = $sku->product()
            ->with([
                'productType:id,code',
                // Deterministic GROUP order too (#2109): the autofill loop below
                // walks these groups in order, so an unordered load makes the
                // sequence of auto-added toppings depend on row order.
                'toppingGroups' => fn ($q) => $q
                    ->wherePivot('product_id', $sku->product_id)
                    ->where('topping_groups.is_active', true)
                    ->orderBy('product_topping_groups.sort_order')
                    ->orderBy('product_topping_groups.id'),
                // `sort_order` is not unique (#2046). This one is MONEY-affecting:
                // the autofill below does `->take($effectiveMin)`, so on a tie the
                // set of toppings picked (and therefore charged) could differ
                // between two identical orders. Tie-break on the unique UUIDv7 id.
                'toppingGroups.items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'toppingGroups.items.skus.productSku',
            ])
            ->first();

        if ($product === null || $product->toppingGroups->isEmpty()) {
            return $toppings;
        }

        $isCombo = $product->productType?->code === 'combo';

        // Determine which groups already have a selection in $toppings so we
        // never override an explicit (even partial) customer choice.
        $providedGroupIds = [];
        if ($toppings !== []) {
            $itemIds = collect($toppings)->pluck('topping_group_item_id')->unique()->all();
            $providedGroupIds = ToppingGroupItem::whereIn('id', $itemIds)
                ->pluck('topping_group_id')
                ->unique()
                ->all();
        }

        foreach ($product->toppingGroups as $group) {
            $effectiveMin = (int) ($group->pivot->min_select_override ?? $group->min_select);
            if ($effectiveMin < 1) {
                continue;
            }
            if (in_array($group->id, $providedGroupIds, true)) {
                continue;
            }

            $candidates = $group->items->where('is_default', true)->values();
            if ($candidates->isEmpty() && $isCombo) {
                $candidates = $group->items->values();
            }
            $candidates = $candidates->take($effectiveMin);

            foreach ($candidates as $item) {
                $itemSku = $item->skus->first();
                $productSkuId = $itemSku?->productSku?->id;
                if ($productSkuId === null) {
                    continue;
                }

                $toppings[] = [
                    'topping_group_item_id' => (string) $item->id,
                    'product_sku_id' => (string) $productSkuId,
                    'quantity' => 1,
                ];
            }
        }

        return $toppings;
    }

    /**
     * Assert that every product_topping_groups row whose effective min_select
     * ≥ 1 has at least one selection in $providedGroupIds.
     *
     * @param  array<int, string>  $providedGroupIds
     */
    private function assertNoMandatoryGroupsMissing(ProductSku $sku, array $providedGroupIds): void
    {
        $product = $sku->product;
        if ($product === null) {
            return;
        }

        $product->loadMissing('toppingGroups');
        foreach ($product->toppingGroups as $group) {
            $effectiveMin = (int) ($group->pivot->min_select_override ?? $group->min_select);
            if ($effectiveMin < 1) {
                continue;
            }
            if (! in_array($group->id, $providedGroupIds, true)) {
                throw ValidationException::withMessages([
                    'items.toppings' => "toppings_below_min: group {$group->id} is mandatory (min_select={$effectiveMin}) but no selection was provided",
                ]);
            }
        }
    }
}
