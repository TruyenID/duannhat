<?php

namespace Database\Factories;

use App\Models\StockCount;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * StockCount Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<StockCount>
 */
class StockCountFactory extends Factory
{
    protected $model = StockCount::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'count_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'warehouse_id' => Warehouse::query()->inRandomOrder()->first()?->id ?? Warehouse::factory()->create()->id,
            'status' => fake()->randomElement(['draft', 'in_progress', 'pending_approval', 'approved', 'cancelled']),
            'scope' => fake()->randomElement(['full', 'partial']),
            'note' => fake()->paragraphs(3, true),
            'created_by_id' => (string) Str::uuid(),
            'approved_by_id' => (string) Str::uuid(),
            'approved_at' => fake()->dateTime(),
            'completed_at' => fake()->dateTime(),
        ];
    }
}
