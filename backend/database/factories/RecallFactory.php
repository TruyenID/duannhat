<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Recall;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Recall Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Recall>
 */
class RecallFactory extends Factory
{
    protected $model = Recall::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recall_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'root_lot_id' => MaterialLot::query()->inRandomOrder()->first()?->id ?? MaterialLot::factory()->create()->id,
            'scope_type' => fake()->randomElement(['lot', 'supplier_lot', 'material']),
            'status' => fake()->randomElement(['draft', 'active', 'completed', 'cancelled']),
            'reason' => fake()->paragraphs(3, true),
            'affected_lots_count' => fake()->numberBetween(0, 100),
            'affected_orders_count' => fake()->numberBetween(0, 100),
            'initiated_by_id' => (string) Str::uuid(),
            'initiated_at' => fake()->dateTime(),
            'completed_at' => fake()->dateTime(),
            'cancelled_at' => fake()->dateTime(),
            'cancellation_reason' => fake()->paragraphs(3, true),
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
        ];
    }
}
