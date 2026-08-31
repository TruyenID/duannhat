<?php

namespace Database\Factories;

use App\Models\StockTransaction;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * StockTransaction Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<StockTransaction>
 */
class StockTransactionFactory extends Factory
{
    protected $model = StockTransaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'transaction_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'type' => fake()->randomElement(['stock_in', 'stock_out']),
            'sub_type' => fake()->randomElement(['purchase', 'sales', 'production', 'transfer_in', 'transfer_out', 'return', 'disposal', 'adjustment_in', 'adjustment_out', 'other']),
            'warehouse_id' => Warehouse::query()->inRandomOrder()->first()?->id ?? Warehouse::factory()->create()->id,
            'status' => fake()->randomElement(['draft', 'pending', 'approved', 'completed', 'cancelled']),
            'reference_type' => fake()->words(3, true),
            'reference_id' => (string) Str::uuid(),
            'note' => fake()->paragraphs(3, true),
            'created_by_id' => (string) Str::uuid(),
            'approved_by_id' => (string) Str::uuid(),
            'approved_at' => fake()->dateTime(),
            'completed_at' => fake()->dateTime(),
        ];
    }
}
