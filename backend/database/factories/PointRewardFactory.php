<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\PointReward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PointReward Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PointReward>
 */
class PointRewardFactory extends Factory
{
    protected $model = PointReward::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->paragraphs(3, true),
            'cost_points' => fake()->numberBetween(100, 10000),
            'discount_type' => fake()->randomElement(['fixed', 'percent']),
            'discount_value' => fake()->randomFloat(2, 1, 10000),
            'max_discount_cap' => fake()->randomFloat(2, 1, 10000),
            'min_order_subtotal' => fake()->randomFloat(2, 1, 10000),
            'valid_days' => fake()->numberBetween(1, 1000),
            'is_active' => fake()->boolean(),
            'sort_order' => fake()->numberBetween(1, 100),
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
