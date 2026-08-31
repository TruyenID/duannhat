<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\TillTenderCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * TillTenderCategory Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<TillTenderCategory>
 */
class TillTenderCategoryFactory extends Factory
{
    protected $model = TillTenderCategory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id,
            'key' => Str::random(32),
            'name' => fake()->sentence(3),
            'sort_order' => fake()->numberBetween(1, 100),
            'is_active' => fake()->boolean(),
            'is_system' => fake()->boolean(),
        ];
    }
}
