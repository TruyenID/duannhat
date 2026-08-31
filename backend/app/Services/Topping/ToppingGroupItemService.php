<?php

namespace App\Services\Topping;

use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ToppingGroupItemService
{
    public function __construct(
        private readonly ProductToppingGroupService $mutations,
    ) {}

    /**
     * Full-sync a group's items to exactly the given product list, in order.
     *
     * The admin edits the product panel client-side (drag in / remove / reorder)
     * and saves ONCE — this replays that snapshot in a single transaction:
     *   - products present now but not in $productIds are removed,
     *   - products in $productIds not present yet are added (which seeds their
     *     price rows via addGroupItem),
     *   - sort_order is rewritten to match the incoming order.
     *
     * @param  array<int, string>  $productIds  ordered product ids
     */
    public function syncItems(ToppingGroup $group, array $productIds): Collection
    {
        // De-dupe while preserving order (a product can appear once per group).
        $ordered = array_values(array_unique($productIds));

        DB::transaction(function () use ($group, $ordered): void {
            $existing = $group->items()->get()->keyBy('product_id');

            // Remove items dropped from the panel.
            foreach ($existing as $productId => $item) {
                if (! in_array((string) $productId, $ordered, true)) {
                    $this->mutations->removeGroupItem($item);
                }
            }

            // Add new + set sort_order to match the incoming order.
            foreach ($ordered as $index => $productId) {
                $item = $existing->get($productId);

                if ($item === null) {
                    $this->mutations->addGroupItem($group, [
                        'product_id' => $productId,
                        'sort_order' => $index,
                    ]);
                } elseif ((int) $item->sort_order !== $index) {
                    $this->mutations->updateGroupItem($item, ['sort_order' => $index]);
                }
            }
        });

        return $this->list($group);
    }

    public function list(ToppingGroup $group): Collection
    {
        return $group->items()
            ->with([
                'product' => fn ($q) => $q->with([
                    'translations',
                    'skus' => fn ($sq) => $sq->where('is_active', true)
                        ->with(['optionValue1', 'optionValue2', 'optionValue3']),
                ]),
                'skus',
            ])
            // `sort_order` is not unique (#2046) — tie-break on the unique
            // UUIDv7 id. This is the list the admin drag-drop panel seeds from,
            // so a non-deterministic order here would let the panel SAVE a
            // different order than the one the admin arranged.
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function addItem(ToppingGroup $group, array $data): ToppingGroupItem
    {
        return $this->mutations->addGroupItem($group, $data);
    }

    public function updateItem(ToppingGroupItem $item, array $data): ToppingGroupItem
    {
        return $this->mutations->updateGroupItem($item, $data);
    }

    public function reorderItems(ToppingGroup $group, array $itemIds): void
    {
        $this->mutations->reorderGroupItems($group, $itemIds);
    }

    public function removeItem(ToppingGroupItem $item): bool
    {
        return $this->mutations->removeGroupItem($item);
    }
}
