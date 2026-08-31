<?php

namespace Database\Factories;

use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MenuProductSku Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MenuProductSku>
 */
class MenuProductSkuFactory extends Factory
{
    protected $model = MenuProductSku::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_product_id' => MenuProduct::query()->inRandomOrder()->first()?->id ?? MenuProduct::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id ?? ProductSku::factory()->create()->id,
            'selling_price' => fake()->randomFloat(2, 1, 10000),
            'is_price_overridden' => fake()->boolean(),
            'is_active' => fake()->boolean(),
        ];
    }
}
