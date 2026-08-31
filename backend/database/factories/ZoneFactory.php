<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Zone Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->regexify('[A-Z]{3}'),
            'name' => fake()->randomElement(['Terrace', 'Indoor', 'VIP Room', 'Bar', 'Window']),
            'description' => null,
            'display_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
