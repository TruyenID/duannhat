<?php

/**
 * ToppingGroupItem Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ToppingGroupItem\Models\ToppingGroupItemBaseModel;
use Database\Factories\ToppingGroupItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * ToppingGroupItem — add project-specific model logic here.
 */
class ToppingGroupItem extends ToppingGroupItemBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ToppingGroupItemFactory
    {
        return ToppingGroupItemFactory::new();
    }

    /**
     * Clean up dependent override rows when a topping item is removed.
     *
     * A shop-level `menu_product_topping_item_overrides` row (or an HQ-level
     * `product_topping_group_item_overrides` row) that outlives its item becomes
     * an orphan: it can never apply, yet the admin Shop→Menu save path passes it
     * back and the sync validation then rejects the whole request. Purging on
     * delete keeps the override tables consistent with the live item set.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::deleting(function (self $item): void {
            MenuProductToppingItemOverride::where('topping_group_item_id', $item->id)->delete();
            FloatingSectionProductToppingItemOverride::where('topping_group_item_id', $item->id)->delete();
            ProductToppingGroupItemOverride::where('topping_group_item_id', $item->id)->delete();
        });
    }

    /**
     * ToppingGroupItemSku pivot — links this topping item to specific product SKUs with extra pricing.
     */
    public function skus(): HasMany
    {
        return $this->hasMany(ToppingGroupItemSku::class, 'topping_group_item_id');
    }

    /**
     * Per-product price/visibility overrides set by HQ for a specific product
     * that uses this topping group. Scoped by product_id at the eager-load
     * call site (toppingEagerLoadChain) so only the relevant product's overrides
     * are loaded.
     */
    public function productOverrides(): HasMany
    {
        return $this->hasMany(ProductToppingGroupItemOverride::class, 'topping_group_item_id');
    }

    /**
     * Can a SKU-less ("wildcard") price row on this item still be read?
     *
     * A wildcard row prices any SKU that has no scoped row of its own —
     * `ToppingPricingService` sorts `product_sku_id IS NULL` LAST, so a scoped
     * row always wins for its own SKU. Once EVERY active SKU of the topping's
     * product has a scoped row, the wildcard prices nothing at all.
     *
     * This is the single predicate behind three behaviours that must not drift
     * apart (#1275, #1316):
     *
     *   - `ProductToppingGroupService::createItemSku` refuses to create one.
     *   - dòng wildcard cũ ĐÃ dọn xong; lệnh `toppings:prune-dead-wildcard-prices`
     *     GỠ ở #2507 (production còn 0 dòng). Trước đây nó xoá những dòng có từ
     *     that guard.
     *   - the admin topping panels mark the row "does not apply", because a row
     *     that prices nothing was being drawn as an extra variant — which is how
     *     #1275 was reported ("admin says 2, the customer menu serves 1").
     *
     * A looser rule in any one of the three is a real hazard, not a style
     * nit: the prune would delete rows the guard still considers legitimate
     * (51 live wildcard rows in dev price SKUs nobody has scoped), and the
     * panel would accuse a working price row of doing nothing.
     */
    public function wildcardPriceApplies(): bool
    {
        $activeSkuIds = $this->activeProductSkuIds();

        // No active SKU at all → the wildcard is the ONLY price this topping
        // has. Never dead: "every active SKU is scoped" is vacuously true here,
        // and treating that as dead would strip the one price it carries.
        if ($activeSkuIds->isEmpty()) {
            return true;
        }

        return $activeSkuIds->diff($this->scopedPriceSkuIds())->isNotEmpty();
    }

    /**
     * Active SKU ids of the topping's own product.
     *
     * Reads the loaded relation when the caller eager-loaded it (the admin menu
     * payload renders one row per topping item, so querying per row would be an
     * N+1 on a screen that lists dozens).
     *
     * CONTRACT for anyone adding an eager load of `product.skus`: constrain it
     * to `is_active` or not at all. Narrowing it to a SUBSET of the active SKUs
     * (say, only the ones in the menu being viewed) makes this return fewer ids
     * than really exist, so `wildcardPriceApplies()` concludes "every active SKU
     * is already scoped" and marks a LIVE price row dead — the one direction the
     * flag must never fail in. The opposite slip is harmless: omit `is_active`
     * from the select and every `$sku->is_active` is null, the set is empty, and
     * the predicate returns true (row kept).
     *
     * @return Collection<int, string>
     */
    private function activeProductSkuIds(): Collection
    {
        if ($this->product_id === null) {
            return collect();
        }

        if ($this->relationLoaded('product') && $this->product?->relationLoaded('skus')) {
            return $this->product->skus
                ->filter(fn (ProductSku $sku): bool => (bool) $sku->is_active)
                ->pluck('id')
                ->values();
        }

        return ProductSku::query()
            ->where('product_id', $this->product_id)
            ->where('is_active', true)
            ->pluck('id');
    }

    /**
     * SKU ids this item already prices with a row of their own.
     *
     * @return Collection<int, string>
     */
    private function scopedPriceSkuIds(): Collection
    {
        if ($this->relationLoaded('skus')) {
            return $this->skus
                ->whereNotNull('product_sku_id')
                ->pluck('product_sku_id')
                ->values();
        }

        return ToppingGroupItemSku::query()
            ->where('topping_group_item_id', $this->id)
            ->whereNotNull('product_sku_id')
            ->pluck('product_sku_id');
    }
}
