<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\ProductSku;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * StockMovement Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

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
            'stock_transaction_id' => StockTransaction::query()->inRandomOrder()->first()?->id ?? StockTransaction::factory()->create()->id,
            'movement_type' => fake()->randomElement(['in', 'out']),
            'quantity' => fake()->randomFloat(2, 1, 10000),
            'quantity_before' => fake()->randomFloat(2, 1, 10000),
            'quantity_after' => fake()->randomFloat(2, 1, 10000),
            'unit' => fake()->words(3, true),
        ];
    }
}
