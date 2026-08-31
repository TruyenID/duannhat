<?php

namespace Database\Factories;

use App\Models\FloatingSectionProduct;
use App\Models\FloatingSectionProductToppingItemOverride;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * FloatingSectionProductToppingItemOverride Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<FloatingSectionProductToppingItemOverride>
 */
class FloatingSectionProductToppingItemOverrideFactory extends Factory
{
    protected $model = FloatingSectionProductToppingItemOverride::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'floating_section_product_id' => FloatingSectionProduct::query()->inRandomOrder()->first()?->id ?? FloatingSectionProduct::factory()->create()->id,
            'topping_group_id' => ToppingGroup::query()->inRandomOrder()->first()?->id ?? ToppingGroup::factory()->create()->id,
            'topping_group_item_id' => ToppingGroupItem::query()->inRandomOrder()->first()?->id ?? ToppingGroupItem::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id,
            'is_hidden' => fake()->boolean(),
            'override_price' => fake()->randomFloat(2, 1, 10000),
        ];
    }
}
