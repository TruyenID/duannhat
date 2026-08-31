<?php

namespace Database\Factories;

use App\Models\ProductSku;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ToppingGroupItemSku Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ToppingGroupItemSku>
 */
class ToppingGroupItemSkuFactory extends Factory
{
    protected $model = ToppingGroupItemSku::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'topping_group_item_id' => fn () => ToppingGroupItem::factory()->create()->id,
            'product_sku_id' => null,
            'extra_price' => 0,
        ];
    }

    /**
     * Simple topping (no variant) — product_sku_id = null.
     */
    public function noVariant(): static
    {
        return $this->state(['product_sku_id' => null]);
    }

    /**
     * Topping with a specific SKU variant.
     */
    public function withSku(ProductSku $sku): static
    {
        return $this->state(['product_sku_id' => $sku->id]);
    }
}
