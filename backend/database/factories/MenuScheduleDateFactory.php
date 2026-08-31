<?php

namespace Database\Factories;

use App\Models\MenuSchedule;
use App\Models\MenuScheduleDate;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MenuScheduleDate Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MenuScheduleDate>
 */
class MenuScheduleDateFactory extends Factory
{
    protected $model = MenuScheduleDate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => fake()->date(),
            'menu_schedule_id' => MenuSchedule::query()->inRandomOrder()->first()?->id ?? MenuSchedule::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
