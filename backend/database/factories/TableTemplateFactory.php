<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\TableTemplate;
use App\Models\ZoneTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * TableTemplate Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<TableTemplate>
 */
class TableTemplateFactory extends Factory
{
    protected $model = TableTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TT-'.fake()->unique()->regexify('[A-Z0-9]{6}'),
            'name' => fake()->optional()->words(2, true),
            'seat_count' => fake()->numberBetween(1, 8),
            'is_active' => true,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'zone_template_id' => ZoneTemplate::query()->inRandomOrder()->first()?->id ?? ZoneTemplate::factory()->create()->id,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
