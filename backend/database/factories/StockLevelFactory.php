<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\ProductSku;
use App\Models\StockLevel;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * StockLevel Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<StockLevel>
 */
class StockLevelFactory extends Factory
{
    protected $model = StockLevel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::query()->inRandomOrder()->first()?->id ?? Warehouse::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id,
            'material_id' => Material::query()->inRandomOrder()->first()?->id,
            'quantity' => fake()->randomFloat(2, 1, 10000),
            'unit' => fake()->words(3, true),
            'min_stock' => fake()->randomFloat(2, 1, 10000),
            'max_stock' => fake()->randomFloat(2, 1, 10000),
            'alert_enabled' => fake()->boolean(),
        ];
    }
}
