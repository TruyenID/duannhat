<?php

namespace Database\Factories;

use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MenuProduct Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MenuProduct>
 */
class MenuProductFactory extends Factory
{
    protected $model = MenuProduct::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_id' => Menu::query()->inRandomOrder()->first()?->id ?? Menu::factory()->create()->id,
            'product_id' => Product::query()->inRandomOrder()->first()?->id ?? Product::factory()->create()->id,
            'is_active' => fake()->boolean(),
            'display_order' => fake()->numberBetween(1, 100),
            'master_menu_product_id' => MenuProduct::query()->inRandomOrder()->first()?->id,
        ];
    }
}
