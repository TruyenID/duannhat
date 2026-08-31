<?php

namespace App\Services\Topping;

use App\Models\MenuProduct;
use App\Models\MenuProductToppingItemOverride;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manages shop-level topping extra_price / visibility overrides.
 *
 * These overrides are scoped to a single MenuProduct (branch menu entry)
 * and sit ABOVE the HQ product_topping_group_item_overrides in the
 * resolution chain:
 *
 *   shop override  →  HQ override  →  HQ base extra_price
 */
class ShopMenuToppingOverrideService
{
    use ValidatesToppingOverridePayload;

    /**
     * List all shop-level overrides for a (menu_product, topping_group) pair.
     *
     * @return Collection<int, MenuProductToppingItemOverride>
     */
    public function list(MenuProduct $menuProduct, ToppingGroup $group): Collection
    {
        return MenuProductToppingItemOverride::where('menu_product_id', $menuProduct->id)
            ->where('topping_group_id', $group->id)
            ->get();
    }

    /**
     * Replace all shop-level overrides for a (menu_product, topping_group) pair.
     *
     * Validation:
     *   - (menu_product.product_id, group_id) attachment must exist
     *   - topping_group_item_id must belong to topping_group_id
     *   - product_sku_id (if set) must belong to the item's product
     *   - is_hidden=true → override_price must be null
     *   - no duplicate (topping_group_item_id, product_sku_id) in payload
     *
     * Passing $overrides=[] removes all overrides for the pair (valid).
     *
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string|null, is_hidden: bool, override_price: numeric|null}>  $overrides
     * @return Collection<int, MenuProductToppingItemOverride>
     */
    public function sync(MenuProduct $menuProduct, ToppingGroup $group, array $overrides): Collection
    {
        // Prune soft-deleted-item rows + validate against the catalog/payload.
        // Owner-agnostic — shared with the floating section override service.
        $overrides = $this->validateToppingOverrides($group, $overrides);

        DB::transaction(function () use ($menuProduct, $group, $overrides) {
            MenuProductToppingItemOverride::where('menu_product_id', $menuProduct->id)
                ->where('topping_group_id', $group->id)
                ->delete();

            foreach ($overrides as $row) {
                MenuProductToppingItemOverride::create([
                    'menu_product_id' => $menuProduct->id,
                    'topping_group_id' => $group->id,
                    'topping_group_item_id' => $row['topping_group_item_id'],
                    'product_sku_id' => $row['product_sku_id'] ?? null,
                    'is_hidden' => (bool) ($row['is_hidden'] ?? false),
                    'override_price' => $row['override_price'] ?? null,
                ]);
            }
        });

        return $this->list($menuProduct, $group);
    }

    /**
     * plan-056 — hide or show ONE topping on ONE dish, from the POS.
     *
     * ## Why this is not `sync()`
     *
     * `sync()` DELETES every override for the (menu_product, topping_group)
     * pair and rewrites the payload. That is right for the admin screen, which
     * always holds and replays the whole set. It is wrong for a POS toggle:
     *
     *   · Sending only the one change wipes every sibling override — including
     *     the shop's topping PRICES, which nobody asked to touch.
     *   · Sending the whole set means a price a manager saved in admin-web a
     *     second earlier is overwritten by whatever the POS last read.
     *
     * This method writes exactly one row and reads none of the others.
     *
     * ## It writes the WILDCARD row (product_sku_id = null)
     *
     * "Hết trứng chần" is a statement about the topping, not about one variant
     * of it, and that is the only granularity the POS offers. Per-SKU hiding
     * stays admin-web's.
     *
     * ## Turning it back ON clears EVERY row's hide, not just the wildcard's
     *
     * The read path treats a topping as hidden when ANY shop override row says
     * so. Clearing only the wildcard would leave a sku-scoped hide standing,
     * and the POS would report the topping back on sale while it stayed
     * invisible — the exact silent failure this screen exists to prevent.
     *
     * A row left carrying neither a price nor a hide is DELETED rather than
     * kept: #1203 established that an empty tier-1 row still outranks tier-2
     * on the LAN, so an empty row is not neutral, it is a price bug in waiting.
     */
    public function setItemHiddenForShop(
        MenuProduct $menuProduct,
        ToppingGroupItem $item,
        bool $hidden,
    ): bool {
        // Returns whether anything actually CHANGED. The caller logs an
        // availability event only when it did: `sync_queue` delivery is
        // at-least-once, and a replay that appended an event every time would
        // inflate "how often was this topping out of stock" with retries that
        // are not events at all. Same rule the dish and variant paths follow.
        return DB::transaction(function () use ($menuProduct, $item, $hidden): bool {
            $scope = MenuProductToppingItemOverride::query()
                ->where('menu_product_id', $menuProduct->id)
                ->where('topping_group_item_id', $item->id);

            if (! $hidden) {
                $changed = false;
                foreach ((clone $scope)->get() as $row) {
                    // A row that only existed to hide has nothing left to say.
                    if ($row->override_price === null) {
                        $changed = $changed || (bool) $row->is_hidden;
                        $row->delete();

                        continue;
                    }
                    if ($row->is_hidden) {
                        $row->update(['is_hidden' => false]);
                        $changed = true;
                    }
                }

                return $changed;
            }

            $wasHidden = (clone $scope)->where('is_hidden', true)->exists();

            // Hiding: the wildcard row carries it. `is_hidden = true` forces
            // `override_price = null` (the documented constraint on this
            // table), so a shop that had priced this topping loses the price
            // when it hides it — which is why the price is cleared explicitly
            // here rather than left for a NOT-NULL violation to discover.
            MenuProductToppingItemOverride::query()->updateOrCreate(
                [
                    'menu_product_id' => $menuProduct->id,
                    'topping_group_id' => $item->topping_group_id,
                    'topping_group_item_id' => $item->id,
                    'product_sku_id' => null,
                ],
                [
                    'is_hidden' => true,
                    'override_price' => null,
                ],
            );

            // Any sku-scoped row for this item would otherwise keep pricing a
            // topping the shop has just declared unavailable. Hide them too so
            // every tier-1 row agrees with the wildcard.
            (clone $scope)
                ->whereNotNull('product_sku_id')
                ->update(['is_hidden' => true, 'override_price' => null]);

            return ! $wasHidden;
        });
    }

    /**
     * Is this topping currently hidden for this dish by a SHOP override?
     *
     * "Any row says hidden" — the same rule both read paths apply. Deliberately
     * not "the first row", which is what the Cloud resource used to do and
     * which made the answer depend on row order.
     */
    public function isItemHiddenForShop(MenuProduct $menuProduct, ToppingGroupItem $item): bool
    {
        return MenuProductToppingItemOverride::query()
            ->where('menu_product_id', $menuProduct->id)
            ->where('topping_group_item_id', $item->id)
            ->where('is_hidden', true)
            ->exists();
    }
}
