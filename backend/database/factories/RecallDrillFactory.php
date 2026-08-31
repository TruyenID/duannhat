<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\RecallDrill;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * RecallDrill Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<RecallDrill>
 */
class RecallDrillFactory extends Factory
{
    protected $model = RecallDrill::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'triggered_by_id' => User::query()->inRandomOrder()->first()?->id ?? User::factory()->create()->id,
            'selected_lot_id' => MaterialLot::query()->inRandomOrder()->first()?->id ?? MaterialLot::factory()->create()->id,
            'started_at' => fake()->dateTime(),
            'affected_lots_at' => fake()->dateTime(),
            'affected_orders_at' => fake()->dateTime(),
            'completed_at' => fake()->dateTime(),
            'elapsed_seconds' => fake()->numberBetween(1, 300),
            'affected_lots_count' => fake()->numberBetween(0, 50),
            'affected_orders_count' => fake()->numberBetween(0, 200),
            'completeness_percent' => fake()->randomFloat(2, 0, 100),
            'notes' => fake()->paragraphs(3, true),
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
        ];
    }
}
