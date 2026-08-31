<?php

namespace Database\Factories;

use App\Models\MaterialBatch;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * MaterialBatch Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MaterialBatch>
 */
class MaterialBatchFactory extends Factory
{
    protected $model = MaterialBatch::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'batch_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'warehouse_id' => Warehouse::query()->inRandomOrder()->first()?->id ?? Warehouse::factory()->create()->id,
            'material_id' => (string) Str::uuid(),
            'multiplier' => fake()->randomFloat(2, 1, 10000),
            'planned_yield' => fake()->randomFloat(2, 1, 10000),
            'actual_yield' => fake()->randomFloat(2, 1, 10000),
            'yield_unit' => fake()->words(3, true),
            'status' => fake()->randomElement(['draft', 'pending', 'approved', 'in_progress', 'completed', 'cancelled']),
            'stock_out_transaction_id' => (string) Str::uuid(),
            'stock_in_transaction_id' => (string) Str::uuid(),
            'note' => fake()->paragraphs(3, true),
            'created_by_id' => (string) Str::uuid(),
            'approved_by_id' => (string) Str::uuid(),
            'approved_at' => fake()->dateTime(),
            'started_at' => fake()->dateTime(),
            'completed_at' => fake()->dateTime(),
            'expiry_date' => fake()->dateTime(),
        ];
    }
}
