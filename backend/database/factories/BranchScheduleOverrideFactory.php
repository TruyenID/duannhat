<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchScheduleOverride;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * BranchScheduleOverride Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<BranchScheduleOverride>
 */
class BranchScheduleOverrideFactory extends Factory
{
    protected $model = BranchScheduleOverride::class;

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
            'menu_schedule_id' => MenuSchedule::query()->inRandomOrder()->first()?->id ?? MenuSchedule::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'updated_by_id' => User::query()->inRandomOrder()->first()?->id,
        ];
    }
}
