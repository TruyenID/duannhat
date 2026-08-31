<?php

namespace Database\Factories;

use App\Models\ProductionOrder;
use App\Models\ProductSku;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * ProductionOrder Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ProductionOrder>
 */
class ProductionOrderFactory extends Factory
{
    protected $model = ProductionOrder::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'order_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'warehouse_id' => Warehouse::query()->inRandomOrder()->first()?->id ?? Warehouse::factory()->create()->id,
            'output_variant_id' => ProductSku::query()->inRandomOrder()->first()?->id ?? ProductSku::factory()->create()->id,
            'planned_quantity' => fake()->randomFloat(2, 1, 10000),
            'actual_quantity' => fake()->randomFloat(2, 1, 10000),
            'output_unit' => fake()->words(3, true),
            'recipe_multiplier' => fake()->randomFloat(2, 1, 10000),
            'status' => fake()->randomElement(['draft', 'pending', 'approved', 'in_progress', 'completed', 'cancelled']),
            'stock_out_transaction_id' => (string) Str::uuid(),
            'stock_in_transaction_id' => (string) Str::uuid(),
            'note' => fake()->paragraphs(3, true),
            'created_by_id' => (string) Str::uuid(),
            'approved_by_id' => (string) Str::uuid(),
            'approved_at' => fake()->dateTime(),
            'started_at' => fake()->dateTime(),
            'completed_at' => fake()->dateTime(),
        ];
    }
}
