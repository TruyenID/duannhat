<?php

namespace App\Services\Topping;

use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;

/**
 * Shared validation for a shop-level topping-override sync payload.
 *
 * The rules are entirely about the catalog (topping group / item / SKU) and the
 * payload shape — they do NOT depend on the override OWNER (MenuProduct vs
 * FloatingSectionProduct). Both ShopMenuToppingOverrideService and
 * ShopFloatingSectionToppingOverrideService use this so the two owners share one
 * validation surface (the "section first-class" abstraction: owner is just a
 * scope id, everything else is catalog).
 */
trait ValidatesToppingOverridePayload
{
    /**
     * Prune override rows pointing at a soft-deleted topping item, then validate
     * the remaining rows against the topping group / catalog. Returns the pruned
     * list ready for persistence.
     *
     * @param  array<int, array{topping_group_item_id: string, product_sku_id?: string|null, is_hidden?: bool, override_price?: numeric|null}>  $overrides
     * @return array<int, array<string, mixed>>
     *
     * @throws \InvalidArgumentException
     */
    protected function validateToppingOverrides(ToppingGroup $group, array $overrides): array
    {
        if (empty($overrides)) {
            return [];
        }

        // Drop rows whose topping item was soft-deleted. The admin screen replays
        // every existing override on each save; a dangling override for a removed
        // item would otherwise hard-fail the whole request and block sibling edits.
        $liveItemIds = ToppingGroupItem::whereIn('id', array_column($overrides, 'topping_group_item_id'))
            ->pluck('id', 'id');

        $overrides = array_values(array_filter(
            $overrides,
            fn ($row) => isset($liveItemIds[$row['topping_group_item_id']]),
        ));

        if (empty($overrides)) {
            return [];
        }

        $itemIds = array_column($overrides, 'topping_group_item_id');

        // Every item must belong to this topping group.
        $validItemIds = ToppingGroupItem::whereIn('id', $itemIds)
            ->where('topping_group_id', $group->id)
            ->pluck('id', 'id');

        foreach ($itemIds as $itemId) {
            if (! isset($validItemIds[$itemId])) {
                throw new \InvalidArgumentException(
                    "Topping group item {$itemId} does not belong to this topping group."
                );
            }
        }

        // A scoped product_sku_id must belong to the topping item's product.
        $itemProductMap = ToppingGroupItem::whereIn('id', $itemIds)
            ->pluck('product_id', 'id');

        $skuChecks = [];
        foreach ($overrides as $row) {
            if (! empty($row['product_sku_id'])) {
                $itemProductId = $itemProductMap[$row['topping_group_item_id']] ?? null;
                if ($itemProductId) {
                    $skuChecks[$row['product_sku_id']] = $itemProductId;
                }
            }
        }

        if (! empty($skuChecks)) {
            $skuRows = ProductSku::whereIn('id', array_keys($skuChecks))
                ->get(['id', 'product_id'])
                ->keyBy('id');

            foreach ($skuChecks as $skuId => $expectedProductId) {
                if (! isset($skuRows[$skuId]) || $skuRows[$skuId]->product_id !== $expectedProductId) {
                    throw new \InvalidArgumentException(
                        "Product SKU {$skuId} does not belong to the item's product."
                    );
                }
            }
        }

        // is_hidden=true → override_price must be null.
        foreach ($overrides as $row) {
            if (! empty($row['is_hidden']) && isset($row['override_price'])) {
                throw new \InvalidArgumentException(
                    'override_price must be null when is_hidden is true.'
                );
            }
        }

        // #1203 — a row that neither hides nor prices carries no information,
        // and the two pricing engines disagreed about what to do with it: Cloud
        // fell through to the HQ tier, the workstation treated the row's mere
        // existence as suppressing that tier and used the catalogue base. Same
        // basket, two prices, and an offline order priced by one and re-priced
        // by the other is rejected as tampered.
        //
        // The admin screen already refuses to send this shape ("only push if
        // the row has meaningful content"), so this closes the API door the UI
        // was quietly holding shut. Deleting the row is how you say "use the
        // group default".
        foreach ($overrides as $row) {
            if (empty($row['is_hidden']) && ($row['override_price'] ?? null) === null) {
                throw new \InvalidArgumentException(
                    'An override row must either hide the topping or set a price; remove the row to fall back to the group default.'
                );
            }
        }

        // No duplicate (item, sku) pairs.
        $seen = [];
        foreach ($overrides as $row) {
            $key = $row['topping_group_item_id'].'__'.($row['product_sku_id'] ?? '__null__');
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(
                    'Duplicate (topping_group_item_id, product_sku_id) pair in payload.'
                );
            }
            $seen[$key] = true;
        }

        return $overrides;
    }
}
