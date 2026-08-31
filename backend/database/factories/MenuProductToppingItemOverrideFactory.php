<?php

namespace Database\Factories;

use App\Models\MenuProduct;
use App\Models\MenuProductToppingItemOverride;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MenuProductToppingItemOverride Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MenuProductToppingItemOverride>
 */
class MenuProductToppingItemOverrideFactory extends Factory
{
    protected $model = MenuProductToppingItemOverride::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_product_id' => MenuProduct::query()->inRandomOrder()->first()?->id ?? MenuProduct::factory()->create()->id,
            'topping_group_id' => ToppingGroup::query()->inRandomOrder()->first()?->id ?? ToppingGroup::factory()->create()->id,
            'topping_group_item_id' => ToppingGroupItem::query()->inRandomOrder()->first()?->id ?? ToppingGroupItem::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id,
            'is_hidden' => fake()->boolean(),
            'override_price' => fake()->randomFloat(2, 1, 10000),
        ];
    }
}
