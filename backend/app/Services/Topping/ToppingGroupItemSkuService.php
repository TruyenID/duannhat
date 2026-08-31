<?php

namespace App\Services\Topping;

use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use Illuminate\Support\Collection;

class ToppingGroupItemSkuService
{
    public function __construct(
        private readonly ProductToppingGroupService $mutations,
    ) {}

    public function list(ToppingGroupItem $item): Collection
    {
        return $this->mutations->listItemSkus($item);
    }

    /** @param  array{product_sku_id: string|null, extra_price: numeric}  $data */
    public function create(ToppingGroupItem $item, array $data): ToppingGroupItemSku
    {
        return $this->mutations->createItemSku($item, $data);
    }

    /** @param  array{extra_price: numeric}  $data */
    public function update(ToppingGroupItemSku $sku, array $data): ToppingGroupItemSku
    {
        return $this->mutations->updateItemSku($sku, $data);
    }

    public function delete(ToppingGroupItemSku $sku): bool
    {
        return $this->mutations->deleteItemSku($sku);
    }
}
