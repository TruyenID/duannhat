<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\FloatingSection;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * FloatingSection Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<FloatingSection>
 */
class FloatingSectionFactory extends Factory
{
    protected $model = FloatingSection::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'is_active' => fake()->boolean(),
            'priority' => fake()->numberBetween(1, 1000),
            'start_date' => fake()->date(),
            'end_date' => fake()->date(),
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
        ];
    }
}
