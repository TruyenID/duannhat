<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroupItemOverride;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ProductToppingGroupItemOverride Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ProductToppingGroupItemOverride>
 */
class ProductToppingGroupItemOverrideFactory extends Factory
{
    protected $model = ProductToppingGroupItemOverride::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::query()->inRandomOrder()->first()?->id ?? Product::factory()->create()->id,
            'topping_group_id' => ToppingGroup::query()->inRandomOrder()->first()?->id ?? ToppingGroup::factory()->create()->id,
            'topping_group_item_id' => ToppingGroupItem::query()->inRandomOrder()->first()?->id ?? ToppingGroupItem::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id,
            'is_hidden' => fake()->boolean(),
            'override_price' => fake()->randomFloat(2, 1, 10000),
        ];
    }
}
