<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\ProductSku;
use App\Models\StockAlert;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * StockAlert Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<StockAlert>
 */
class StockAlertFactory extends Factory
{
    protected $model = StockAlert::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'warehouse_id' => Warehouse::query()->inRandomOrder()->first()?->id ?? Warehouse::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id,
            'material_id' => Material::query()->inRandomOrder()->first()?->id,
            'alert_type' => fake()->randomElement(['low_stock', 'out_of_stock']),
            'current_quantity' => fake()->randomFloat(2, 1, 10000),
            'min_stock' => fake()->randomFloat(2, 1, 10000),
            'unit' => fake()->words(3, true),
            'status' => fake()->randomElement(['active', 'resolved']),
            'resolved_at' => fake()->dateTime(),
        ];
    }
}
