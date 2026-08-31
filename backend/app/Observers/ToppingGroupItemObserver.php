<?php

namespace App\Observers;

use App\Models\ToppingGroupItem;
use App\Services\Topping\ProductToppingGroupService;

/**
 * When a ToppingGroupItem is soft-deleted, hard-delete all related
 * ToppingGroupItemSku rows so price config doesn't linger for a deleted item.
 *
 * MySQL RESTRICT on toppingGroupItem FK in order_item_toppings (Phase 2)
 * prevents soft-deleting an item that still has live order references.
 */
class ToppingGroupItemObserver
{
    public function __construct(
        private readonly ProductToppingGroupService $toppings,
    ) {}

    public function deleted(ToppingGroupItem $item): void
    {
        $this->toppings->purgeItemSkus($item);
    }
}
