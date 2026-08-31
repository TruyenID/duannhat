<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\ZoneTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ZoneTemplate Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ZoneTemplate>
 */
class ZoneTemplateFactory extends Factory
{
    protected $model = ZoneTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'ZT-'.fake()->unique()->regexify('[A-Z0-9]{6}'),
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'display_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
