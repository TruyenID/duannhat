<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchFloatingSectionOverride;
use App\Models\FloatingSectionSchedule;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * BranchFloatingSectionOverride Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<BranchFloatingSectionOverride>
 */
class BranchFloatingSectionOverrideFactory extends Factory
{
    protected $model = BranchFloatingSectionOverride::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'start_time' => fake()->time(),
            'end_time' => fake()->time(),
            'days_of_week' => fake()->numberBetween(0, 127),
            'floating_section_schedule_id' => FloatingSectionSchedule::query()->inRandomOrder()->first()?->id ?? FloatingSectionSchedule::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'updated_by_id' => User::query()->inRandomOrder()->first()?->id,
        ];
    }
}
