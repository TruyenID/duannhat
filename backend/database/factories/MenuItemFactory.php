<?php

namespace Database\Factories;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MenuItem Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_id' => Menu::query()->inRandomOrder()->first()?->id ?? Menu::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id ?? ProductSku::factory()->create()->id,
            'selling_price' => fake()->randomFloat(2, 1, 10000),
            'availability' => fake()->randomElement(['Available', 'Unavailable', 'OutOfStock']),
            'display_order' => fake()->numberBetween(1, 100),
            'is_price_overridden' => fake()->boolean(),
            'master_price' => fake()->randomFloat(2, 1, 10000),
            'master_item_id' => MenuItem::query()->inRandomOrder()->first()?->id,
        ];
    }
}
