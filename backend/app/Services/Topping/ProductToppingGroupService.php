<?php

namespace App\Services\Topping;

use App\Exceptions\ToppingGroupInUseException;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductToppingGroupItemOverride;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Catalog\CatalogRevisionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductToppingGroupService
{
    public function listForProduct(Product $product): Collection
    {
        return ToppingGroup::query()
            ->select('topping_groups.*')
            ->join('product_topping_groups', function ($join) use ($product) {
                $join->on('topping_groups.id', '=', 'product_topping_groups.topping_group_id')
                    ->where('product_topping_groups.product_id', '=', $product->id);
            })
            ->with(['items.product.translations', 'translations'])
            // Tie-break on the pivot's unique id (#2109). This is the list the
            // admin product screen seeds its drag-drop panel from, so a
            // non-deterministic order here would let the panel SAVE a different
            // order than the one the admin arranged — read-wrong becomes
            // write-wrong.
            ->orderBy('product_topping_groups.sort_order')
            ->orderBy('product_topping_groups.id')
            ->get();
    }

    /**
     * @param  array<int, string>  $groupIds  UUIDs of topping groups to assign
     * @param  array<string, int>  $sortOrders  Map of group UUID → sort_order
     * @param  array<string, array{min?: int|null, max?: int|null}>  $overrides
     *                                                                           Map of group UUID → { min: ..., max: ... } per-product overrides.
     *                                                                           Either key may be omitted/null ⇒ inherit group default. Values
     *                                                                           get validated against the group's own bounds (override min must
     *                                                                           be ≤ group.max_select; override max must be ≥ group.min_select)
     *                                                                           so we can't ship a 'must-pick-5' override on a 3-item group.
     */
    public function syncForProduct(Product $product, array $groupIds, array $sortOrders = [], array $overrides = []): Collection
    {
        // Khử trùng lặp NGAY ĐẦU VÀO — không phải ở vòng create() bên dưới.
        //
        // #1861: production ném `Duplicate entry ... product_id_topping_group_id_unique`
        // 9 lần, vì vòng lặp create() chạy đúng theo số phần tử của mảng. Gửi
        // cùng một nhóm hai lần là chuyện thường ở tầng gọi (form gửi lặp, bấm
        // hai lần, payload ghép từ hai nguồn) và nó vẫn chỉ có nghĩa là gắn một
        // lần — nên đây là việc của service, không phải lỗi người gọi.
        //
        // Khử ở đầu để mọi thứ phía sau (kiểm cross-brand, kiểm bounds, sync)
        // đều nhìn thấy cùng một danh sách. Khử ở vòng create() thì các bước
        // kiểm phía trên vẫn chạy thừa trên id lặp.
        $groupIds = array_values(array_unique($groupIds));

        if (! empty($groupIds)) {
            $invalidCount = ToppingGroup::whereIn('id', $groupIds)
                ->where('brand_id', '!=', $product->brand_id)
                ->count();

            if ($invalidCount > 0) {
                throw new \InvalidArgumentException('Cross-brand group assignment not allowed.');
            }

            // Override bounds check — pre-load all referenced groups in one query
            // to avoid N+1 inside the foreach validation loop.
            $groupsById = ToppingGroup::whereIn('id', $groupIds)
                ->get(['id', 'min_select', 'max_select'])
                ->keyBy('id');

            foreach ($groupIds as $groupId) {
                $g = $groupsById[$groupId] ?? null;
                if (! $g) {
                    continue; // already filtered by exists rule at request layer
                }
                $minOverride = $overrides[$groupId]['min'] ?? null;
                $maxOverride = $overrides[$groupId]['max'] ?? null;

                if ($minOverride !== null && $g->max_select !== null && (int) $minOverride > (int) $g->max_select) {
                    throw new \InvalidArgumentException(sprintf(
                        'min_select_override=%d for group %s exceeds group.max_select=%d.',
                        $minOverride, $groupId, $g->max_select,
                    ));
                }
                if ($maxOverride !== null && (int) $maxOverride < (int) $g->min_select) {
                    throw new \InvalidArgumentException(sprintf(
                        'max_select_override=%d for group %s is below group.min_select=%d.',
                        $maxOverride, $groupId, $g->min_select,
                    ));
                }
                if ($minOverride !== null && $maxOverride !== null && (int) $minOverride > (int) $maxOverride) {
                    throw new \InvalidArgumentException(sprintf(
                        'min_select_override (%d) cannot exceed max_select_override (%d) for group %s.',
                        $minOverride, $maxOverride, $groupId,
                    ));
                }
            }
        }

        DB::transaction(function () use ($product, $groupIds, $sortOrders, $overrides) {
            // Bulk query delete fires no model events, so the #1114 revision
            // observer would miss a detach-to-empty sync; mark explicitly.
            app(CatalogRevisionService::class)->markProductDirty((string) $product->id);
            ProductToppingGroup::where('product_id', $product->id)->delete();

            foreach ($groupIds as $index => $groupId) {
                ProductToppingGroup::create([
                    'product_id' => $product->id,
                    'topping_group_id' => $groupId,
                    // #2109 — an omitted entry used to fall back to 0, so a client
                    // that sent no map put EVERY group at position 0 (157 such rows
                    // in dev). `sort_order` has no uniqueness constraint, so the
                    // tie then resolved by physical row order and the customer saw
                    // a different group order than the admin arranged. The array
                    // order IS the intended order — fall back to it, not to 0.
                    'sort_order' => $sortOrders[$groupId] ?? $index,
                    'min_select_override' => $overrides[$groupId]['min'] ?? null,
                    'max_select_override' => $overrides[$groupId]['max'] ?? null,
                ]);
            }
        });

        return $this->listForProduct($product);
    }

    public function listOverrides(Product $product, ToppingGroup $group): Collection
    {
        return ProductToppingGroupItemOverride::where('product_id', $product->id)
            ->where('topping_group_id', $group->id)
            ->get();
    }

    /**
     * Replace all override rows for the given (product, group) attachment.
     *
     * Validation:
     *   - (product_id, group_id) attachment must exist in product_topping_groups
     *   - topping_group_item_id must belong to topping_group_id
     *   - product_sku_id (if non-null) must belong to the item's product
     *   - is_hidden=true → override_price must be null
     *   - no duplicate (topping_group_item_id, product_sku_id) in the payload
     *
     * Passing $overrides=[] removes all overrides for the attachment (valid).
     *
     * Orphan-row policy: detaching the group does NOT cascade-delete these rows;
     * they self-restore when the group is re-attached.
     *
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string|null, is_hidden: bool, override_price: numeric|null}>  $overrides
     */
    public function syncOverrides(Product $product, ToppingGroup $group, array $overrides): Collection
    {
        $attachmentExists = ProductToppingGroup::where('product_id', $product->id)
            ->where('topping_group_id', $group->id)
            ->exists();

        if (! $attachmentExists) {
            throw new \InvalidArgumentException('Product is not attached to this topping group.');
        }

        if (! empty($overrides)) {
            $itemIds = array_column($overrides, 'topping_group_item_id');

            // Verify all items belong to this group (one query).
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

            // Build product_id → item_id map for SKU ownership check.
            $itemProductMap = ToppingGroupItem::whereIn('id', $itemIds)
                ->pluck('product_id', 'id');

            // Collect all non-null SKU IDs and verify ownership in one query.
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
                // Single query: fetch all SKU rows and verify (id, product_id) pairs in PHP.
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
        }

        DB::transaction(function () use ($product, $group, $overrides) {
            // Bulk query delete fires no model events — see syncForProduct above.
            app(CatalogRevisionService::class)->markProductDirty((string) $product->id);
            ProductToppingGroupItemOverride::where('product_id', $product->id)
                ->where('topping_group_id', $group->id)
                ->delete();

            foreach ($overrides as $row) {
                ProductToppingGroupItemOverride::create([
                    'product_id' => $product->id,
                    'topping_group_id' => $group->id,
                    'topping_group_item_id' => $row['topping_group_item_id'],
                    'product_sku_id' => $row['product_sku_id'] ?? null,
                    'is_hidden' => (bool) ($row['is_hidden'] ?? false),
                    'override_price' => $row['override_price'] ?? null,
                ]);
            }
        });

        return $this->listOverrides($product, $group);
    }

    // =========================================================================
    //  Topping group catalog mutations (canonical product aggregate boundary)
    // =========================================================================

    public function createGroup(array $data): ToppingGroup
    {
        return DB::transaction(function () use ($data) {
            $data['sort_order'] = $this->nextGroupSortOrder($data['brand_id']);

            if (($data['price_strategy'] ?? 'flat') === 'flat') {
                $data['free_quantity'] = null;
            }

            return ToppingGroup::create($data)->load('translations');
        });
    }

    public function updateGroup(ToppingGroup $group, array $data): ToppingGroup
    {
        $effectiveStrategy = $data['price_strategy'] ?? $group->price_strategy?->value ?? 'flat';
        if ($effectiveStrategy === 'flat') {
            $data['free_quantity'] = null;
        }

        $group->update($data);

        return $group->load('translations');
    }

    public function deleteGroup(ToppingGroup $group): bool
    {
        $usedBy = $group->products()
            ->select('products.id', 'products.name')
            ->limit(20)
            ->get()
            ->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name])
            ->all();

        if ($usedBy !== []) {
            throw new ToppingGroupInUseException(
                'Cannot delete topping group: still in use by '.count($usedBy).' product(s).',
                $usedBy,
            );
        }

        return $group->delete();
    }

    public function restoreGroup(ToppingGroup $group): ToppingGroup
    {
        $group->restore();

        return $group->load('translations');
    }

    /** @param  array<int, string>  $groupIds */
    public function reorderGroups(string $brandId, array $groupIds): void
    {
        DB::transaction(function () use ($brandId, $groupIds) {
            foreach ($groupIds as $index => $id) {
                ToppingGroup::where('brand_id', $brandId)
                    ->where('id', $id)
                    ->update(['sort_order' => $index + 1]);
            }
        });
    }

    public function addGroupItem(ToppingGroup $group, array $data): ToppingGroupItem
    {
        $product = Product::with(['skus'])->findOrFail($data['product_id']);

        if ($product->brand_id !== $group->brand_id) {
            throw new \InvalidArgumentException(
                'Product does not belong to the same brand as the topping group.'
            );
        }

        return DB::transaction(function () use ($group, $product, $data) {
            $existing = ToppingGroupItem::withTrashed()
                ->where('topping_group_id', $group->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if ($existing && is_null($existing->deleted_at)) {
                throw new \InvalidArgumentException(
                    'Product is already in this topping group.'
                );
            }

            if (($data['is_default'] ?? false) && $group->selection_type?->value === 'single') {
                $existingDefault = ToppingGroupItem::where('topping_group_id', $group->id)
                    ->where('is_default', true)
                    ->lockForUpdate()
                    ->exists();
                if ($existingDefault) {
                    throw new \InvalidArgumentException(
                        'A single-select topping group can have at most one is_default=true item. Untick the existing default first.'
                    );
                }
            }

            // #2046 — an omitted `sort_order` used to default to 0, which silently
            // collided with whatever already sat at position 0. `sort_order` has
            // no uniqueness constraint, so the tie then resolved by physical row
            // order and the customer saw a different topping order than the admin
            // arranged. Append to the end instead: that is what "added an item"
            // means, and it cannot collide. An explicit value is still honoured —
            // `syncItems()` sets positions deliberately and must keep winning.
            // Computed inside the existing lockForUpdate transaction, so two
            // concurrent adds can't read the same MAX.
            $sortOrder = $data['sort_order'] ?? (
                (int) ToppingGroupItem::where('topping_group_id', $group->id)->max('sort_order') + 1
            );

            if ($existing) {
                $existing->restore();
                $existing->update([
                    'sort_order' => $sortOrder,
                    'is_default' => (bool) ($data['is_default'] ?? false),
                ]);
                $item = $existing;
            } else {
                $item = ToppingGroupItem::create([
                    'topping_group_id' => $group->id,
                    'product_id' => $product->id,
                    'sort_order' => $sortOrder,
                    'is_default' => (bool) ($data['is_default'] ?? false),
                ]);
            }

            $variantSkus = $product->skus->filter(fn ($sku) => $sku->option_value1_id !== null);
            $hasVariants = $variantSkus->isNotEmpty();

            if ($item->skus()->doesntExist()) {
                if ($hasVariants) {
                    // A variant product (Size/Temp/… options) needs ONE price row
                    // per SKU, or the shop/customer menu — which reads
                    // topping_group_item_skus, not product.skus — sees no variants
                    // and collapses the topping to a single fallback line. Seed a
                    // zero-delta row for every ACTIVE variant so all of them show.
                    foreach ($variantSkus->where('is_active', true) as $sku) {
                        ToppingGroupItemSku::create([
                            'topping_group_item_id' => $item->id,
                            'product_sku_id' => $sku->id,
                            'extra_price' => 0,
                        ]);
                    }
                } else {
                    // Simple topping (no variant options): bind the product's own
                    // SKU so the POS/workstation menu can resolve a non-null
                    // product_sku_id. The order-write path REQUIRES it
                    // (CustomerOrderStoreRequest + order_item_toppings.product_sku_id
                    // is NOT NULL), and a NULL "wildcard" row renders the topping
                    // silently unclickable in POS (#1708). Prefer an active SKU,
                    // fall back to the first so a temporarily-inactive single SKU
                    // still yields a selectable topping. Mirrors the variant branch
                    // above and ProductToppingSeeder::attachItems.
                    $simpleSku = $product->skus->firstWhere('is_active', true)
                        ?? $product->skus->first();
                    ToppingGroupItemSku::create([
                        'topping_group_item_id' => $item->id,
                        'product_sku_id' => $simpleSku?->id,
                        'extra_price' => 0,
                    ]);
                }
            }

            return $item->load(['product.translations', 'skus']);
        });
    }

    public function updateGroupItem(ToppingGroupItem $item, array $data): ToppingGroupItem
    {
        if (($data['is_default'] ?? false) === true) {
            $item->loadMissing('toppingGroup');
            if ($item->toppingGroup?->selection_type?->value === 'single') {
                $existingDefault = ToppingGroupItem::where('topping_group_id', $item->topping_group_id)
                    ->where('is_default', true)
                    ->where('id', '!=', $item->id)
                    ->exists();
                if ($existingDefault) {
                    throw new \InvalidArgumentException(
                        'A single-select topping group can have at most one is_default=true item. Untick the existing default first.'
                    );
                }
            }
        }

        $update = [];
        if (array_key_exists('sort_order', $data)) {
            $update['sort_order'] = $data['sort_order'];
        }
        if (array_key_exists('is_default', $data)) {
            $update['is_default'] = (bool) $data['is_default'];
        }

        if ($update !== []) {
            $item->update($update);
        }

        return $item->load(['product.translations', 'skus']);
    }

    /** @param  array<int, string>  $itemIds */
    public function reorderGroupItems(ToppingGroup $group, array $itemIds): void
    {
        DB::transaction(function () use ($group, $itemIds) {
            foreach ($itemIds as $index => $itemId) {
                ToppingGroupItem::where('id', $itemId)
                    ->where('topping_group_id', $group->id)
                    ->update(['sort_order' => $index]);
            }
        });
    }

    public function removeGroupItem(ToppingGroupItem $item): bool
    {
        $this->purgeItemSkus($item);

        return $item->delete();
    }

    public function purgeItemSkus(ToppingGroupItem $item): void
    {
        $item->skus()->delete();
    }

    public function listItemSkus(ToppingGroupItem $item): EloquentCollection
    {
        return $item->skus()->with('productSku')->get();
    }

    /** @param  array{product_sku_id: string|null, extra_price: numeric}  $data */
    public function createItemSku(ToppingGroupItem $item, array $data): ToppingGroupItemSku
    {
        if ($data['product_sku_id'] === null) {
            $exists = ToppingGroupItemSku::where('topping_group_item_id', $item->id)
                ->whereNull('product_sku_id')
                ->exists();

            if ($exists) {
                throw new \InvalidArgumentException('A price override without a SKU already exists for this item.');
            }

            // A SKU-less (wildcard) row only ever prices a SKU that has no
            // scoped row of its own: ToppingPricingService tier 3 sorts
            // `product_sku_id IS NULL` LAST, so a scoped row always wins for
            // its own SKU. If every active SKU of this topping is already
            // scoped, the row being created can never be read — it silently
            // prices nothing while still showing up in admin as an extra
            // "variant", which is exactly the "admin shows 2, customer shows 1"
            // report in #1275 (11 such dead rows in the dev data). Refuse it
            // and say where the price actually belongs.
            //
            // `withoutRelations()` on purpose: the predicate reads loaded
            // relations when they are there (the read path needs that to avoid
            // an N+1), but a caller may hand us an $item whose `skus` were
            // loaded before this request added a row. On a write path a stale
            // read decides wrongly, so force the fresh query here (#1316).
            if (! $item->withoutRelations()->wildcardPriceApplies()) {
                throw new \InvalidArgumentException(
                    'Every active SKU of this topping already has its own price row, so a price without a SKU would never apply. Edit the existing per-SKU price instead.'
                );
            }
        } else {
            $skuBelongsToProduct = ProductSku::where('id', $data['product_sku_id'])
                ->where('product_id', $item->product_id)
                ->exists();

            if (! $skuBelongsToProduct) {
                throw new \InvalidArgumentException("SKU does not belong to this item's product.");
            }
        }

        $item->loadMissing('toppingGroup');
        if ($item->toppingGroup?->modifier_type?->value === 'remove' && (float) $data['extra_price'] > 0) {
            throw new \InvalidArgumentException(
                'Items in a remove-modifier group cannot have a positive extra_price (use 0 or negative for a discount).'
            );
        }

        return ToppingGroupItemSku::create([
            'topping_group_item_id' => $item->id,
            'product_sku_id' => $data['product_sku_id'],
            'extra_price' => $data['extra_price'],
        ]);
    }

    /** @param  array{extra_price: numeric}  $data */
    public function updateItemSku(ToppingGroupItemSku $sku, array $data): ToppingGroupItemSku
    {
        $sku->loadMissing('toppingGroupItem.toppingGroup');
        if ($sku->toppingGroupItem?->toppingGroup?->modifier_type?->value === 'remove' && (float) $data['extra_price'] > 0) {
            throw new \InvalidArgumentException(
                'Items in a remove-modifier group cannot have a positive extra_price (use 0 or negative for a discount).'
            );
        }

        $sku->update(['extra_price' => $data['extra_price']]);

        return $sku->load('productSku');
    }

    public function deleteItemSku(ToppingGroupItemSku $sku): bool
    {
        return $sku->delete();
    }

    /**
     * Renumber topping GROUPS whose `sort_order` collides within a product
     * (#2109) — the pivot-level twin of backfillToppingItemSortOrder().
     *
     * Order-preserving: rows are renumbered 0..n-1 in the SAME order the read
     * paths now produce (`sort_order`, then the pivot's auto-increment `id`), so
     * a product already displaying correctly does not visibly reshuffle.
     *
     * @return list<array{product_id: string, topping_group_id: string, from: int, to: int}>
     */
    public function backfillProductGroupSortOrder(bool $apply): array
    {
        $tiedProductIds = ProductToppingGroup::query()
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('COUNT(*) > 1')
            ->havingRaw('COUNT(DISTINCT sort_order) < COUNT(*)')
            ->pluck('product_id');

        if ($tiedProductIds->isEmpty()) {
            return [];
        }

        $changes = [];

        foreach ($tiedProductIds as $productId) {
            DB::transaction(function () use ($productId, $apply, &$changes): void {
                $rows = ProductToppingGroup::query()
                    ->where('product_id', $productId)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($rows->values() as $index => $row) {
                    if ((int) $row->sort_order === $index) {
                        continue;
                    }

                    $changes[] = [
                        'product_id' => (string) $productId,
                        'topping_group_id' => (string) $row->topping_group_id,
                        'from' => (int) $row->sort_order,
                        'to' => $index,
                    ];

                    if ($apply) {
                        $row->update(['sort_order' => $index]);
                    }
                }
            });
        }

        if ($apply && $changes !== []) {
            Log::info('Backfilled tied product topping GROUP sort_order (#2109)', [
                'rows' => count($changes),
                'products' => $tiedProductIds->count(),
            ]);
        }

        return $changes;
    }

    /**
     * Renumber topping ITEMS whose `sort_order` collides within a group (#2046)
     * — the item-level twin of backfillProductGroupSortOrder().
     *
     * Order-preserving: rows are renumbered 0..n-1 in the SAME order the read
     * paths now produce (`sort_order`, then the UUIDv7 `id`), so a group already
     * displaying correctly does not visibly reshuffle.
     *
     * @return list<array{group_id: string, item_id: string, from: int, to: int}>
     */
    public function backfillToppingItemSortOrder(bool $apply): array
    {
        // Groups holding more than one live item whose `sort_order` values are
        // not all distinct. A tie means the display order between those rows is
        // decided by physical row order, so the customer can see a different
        // order than the admin arranged (#2046).
        $tiedGroupIds = ToppingGroupItem::query()
            ->select('topping_group_id')
            ->groupBy('topping_group_id')
            ->havingRaw('COUNT(*) > 1')
            ->havingRaw('COUNT(DISTINCT sort_order) < COUNT(*)')
            ->pluck('topping_group_id');

        if ($tiedGroupIds->isEmpty()) {
            return [];
        }

        $changes = [];

        foreach ($tiedGroupIds as $groupId) {
            DB::transaction(function () use ($groupId, $apply, &$changes): void {
                // Renumber 0..n-1 preserving the order the customer sees TODAY
                // (the same `sort_order`, `id` key the read paths now use), so a
                // group that already looks right does not visibly reshuffle.
                $items = ToppingGroupItem::query()
                    ->where('topping_group_id', $groupId)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($items->values() as $index => $item) {
                    if ((int) $item->sort_order === $index) {
                        continue;
                    }

                    $changes[] = [
                        'group_id' => (string) $groupId,
                        'item_id' => (string) $item->id,
                        'from' => (int) $item->sort_order,
                        'to' => $index,
                    ];

                    if ($apply) {
                        $item->update(['sort_order' => $index]);
                    }
                }
            });
        }

        if ($apply && $changes !== []) {
            Log::info('Backfilled tied topping item sort_order (#2046)', [
                'rows' => count($changes),
                'groups' => $tiedGroupIds->count(),
            ]);
        }

        return $changes;
    }

    /**
     * Bind the topping product's own SKU onto simple topping items left with a
     * NULL-only (or empty) `product_sku_id` (#1708).
     *
     * @return list<array{item_id: string, product_id: string, action: string, product_sku_id: string, sku_inactive: bool}>
     */
    public function backfillSimpleToppingSkus(bool $apply): array
    {
        // Items whose sku rows carry NO non-null product_sku_id — covers both
        // "rows present but all NULL" and "no rows at all".
        $itemIds = ToppingGroupItem::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('topping_group_item_skus as s')
                    ->whereColumn('s.topping_group_item_id', 'topping_group_items.id')
                    ->whereNotNull('s.product_sku_id');
            })
            ->pluck('id');

        /** @var list<array{item: ToppingGroupItem, sku: ?ProductSku, existing: EloquentCollection, report: array{item_id: string, product_id: string, action: string, product_sku_id: string, sku_inactive: bool}}> $plan */
        $plan = [];

        foreach ($itemIds as $itemId) {
            $item = ToppingGroupItem::with('product.skus')->find($itemId);
            if ($item === null || $item->product === null) {
                continue;
            }

            $hasVariants = $item->product->skus->contains(
                fn ($sku) => $sku->option_value1_id !== null
            );
            if ($hasVariants) {
                $plan[] = $this->backfillPlanRow($item, null, EloquentCollection::make(), 'skipped_variant');

                continue;
            }

            $sku = $item->product->skus->firstWhere('is_active', true)
                ?? $item->product->skus->first();
            if ($sku === null) {
                $plan[] = $this->backfillPlanRow($item, null, EloquentCollection::make(), 'skipped_no_sku');

                continue;
            }

            $existing = ToppingGroupItemSku::where('topping_group_item_id', $item->id)
                ->orderBy('id')
                ->get();
            $action = $existing->isEmpty() ? 'inserted' : 'updated';

            $plan[] = [
                'item' => $item,
                'sku' => $sku,
                'existing' => $existing,
                'report' => $this->backfillPlanRow($item, $sku, $existing, $action)['report'],
            ];
        }

        if ($apply) {
            DB::transaction(function () use ($plan): void {
                foreach ($plan as $p) {
                    if (! in_array($p['report']['action'], ['inserted', 'updated'], true)) {
                        continue;
                    }
                    // Logged before the write so this master-data change leaves a
                    // trail even if the row is later edited.
                    Log::info('#1708: binding simple-topping SKU', $p['report']);

                    /** @var ProductSku $sku */
                    $sku = $p['sku'];
                    if ($p['existing']->isEmpty()) {
                        ToppingGroupItemSku::create([
                            'topping_group_item_id' => $p['item']->id,
                            'product_sku_id' => $sku->id,
                            'extra_price' => 0,
                        ]);
                    } else {
                        // Keep the first wildcard row (carries the price), bind it,
                        // and drop any extra wildcard rows so the (item, sku)
                        // unique key can't collide.
                        $keep = $p['existing']->first();
                        $keep->update(['product_sku_id' => $sku->id]);
                        foreach ($p['existing']->slice(1) as $extra) {
                            $extra->delete();
                        }
                    }
                }
            });
        }

        return array_map(static fn (array $p): array => $p['report'], $plan);
    }

    /**
     * @return array{item: ToppingGroupItem, sku: ?ProductSku, existing: EloquentCollection, report: array{item_id: string, product_id: string, action: string, product_sku_id: string, sku_inactive: bool}}
     */
    private function backfillPlanRow(ToppingGroupItem $item, ?ProductSku $sku, EloquentCollection $existing, string $action): array
    {
        return [
            'item' => $item,
            'sku' => $sku,
            'existing' => $existing,
            'report' => [
                'item_id' => (string) $item->id,
                'product_id' => (string) $item->product_id,
                'action' => $action,
                'product_sku_id' => $sku !== null ? (string) $sku->id : '',
                'sku_inactive' => $sku !== null ? ! (bool) $sku->is_active : false,
            ],
        ];
    }

    private function nextGroupSortOrder(string $brandId): int
    {
        $max = ToppingGroup::where('brand_id', $brandId)->lockForUpdate()->max('sort_order') ?? 0;

        return $max + 1;
    }
}
