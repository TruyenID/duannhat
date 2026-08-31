<?php

namespace App\Services\Topping;

use App\Models\FloatingSectionProduct;
use App\Models\FloatingSectionProductToppingItemOverride;
use App\Models\ToppingGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manages shop-level topping extra_price / visibility overrides for a floating
 * section product — the tier-1 twin of ShopMenuToppingOverrideService.
 *
 * Floating section is "a menu thu nhỏ": overrides are scoped to a single
 * FloatingSectionProduct and sit ABOVE the HQ product_topping_group_item_overrides
 * in the SAME resolution chain used by the menu:
 *
 *   shop override  →  HQ override  →  HQ base extra_price
 *
 * Validation is shared verbatim with the menu service via
 * ValidatesToppingOverridePayload (owner is just a scope id).
 */
class ShopFloatingSectionToppingOverrideService
{
    use ValidatesToppingOverridePayload;

    /**
     * List all shop-level overrides for a (floating_section_product, topping_group) pair.
     *
     * @return Collection<int, FloatingSectionProductToppingItemOverride>
     */
    public function list(FloatingSectionProduct $sectionProduct, ToppingGroup $group): Collection
    {
        return FloatingSectionProductToppingItemOverride::where('floating_section_product_id', $sectionProduct->id)
            ->where('topping_group_id', $group->id)
            ->get();
    }

    /**
     * Replace all shop-level overrides for a (floating_section_product, topping_group)
     * pair. Passing $overrides=[] clears all overrides for the pair (valid).
     *
     * @param  array<int, array{topping_group_item_id: string, product_sku_id?: string|null, is_hidden?: bool, override_price?: numeric|null}>  $overrides
     * @return Collection<int, FloatingSectionProductToppingItemOverride>
     */
    public function sync(FloatingSectionProduct $sectionProduct, ToppingGroup $group, array $overrides): Collection
    {
        $overrides = $this->validateToppingOverrides($group, $overrides);

        DB::transaction(function () use ($sectionProduct, $group, $overrides) {
            FloatingSectionProductToppingItemOverride::where('floating_section_product_id', $sectionProduct->id)
                ->where('topping_group_id', $group->id)
                ->delete();

            foreach ($overrides as $row) {
                FloatingSectionProductToppingItemOverride::create([
                    'floating_section_product_id' => $sectionProduct->id,
                    'topping_group_id' => $group->id,
                    'topping_group_item_id' => $row['topping_group_item_id'],
                    'product_sku_id' => $row['product_sku_id'] ?? null,
                    'is_hidden' => (bool) ($row['is_hidden'] ?? false),
                    'override_price' => $row['override_price'] ?? null,
                ]);
            }
        });

        return $this->list($sectionProduct, $group);
    }
}
