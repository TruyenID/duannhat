<?php

namespace Database\Factories;

use App\Models\Allergen;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Allergen Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Allergen>
 */
class AllergenFactory extends Factory
{
    protected $model = Allergen::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'name' => fake()->sentence(3),
            'jurisdiction' => fake()->randomElement(['jp', 'eu', 'us']),
            'severity' => fake()->randomElement(['mandatory', 'recommended']),
            'is_active' => fake()->boolean(),
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
