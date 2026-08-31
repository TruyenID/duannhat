<?php

namespace Database\Factories;

use App\Models\FloatingSection;
use App\Models\FloatingSectionSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * FloatingSectionSchedule Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<FloatingSectionSchedule>
 */
class FloatingSectionScheduleFactory extends Factory
{
    protected $model = FloatingSectionSchedule::class;

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
            'start_date' => fake()->date(),
            'end_date' => fake()->date(),
            'days_of_week' => fake()->numberBetween(0, 127),
            'is_active' => fake()->boolean(),
            'priority' => fake()->numberBetween(1, 1000),
            'created_by_id' => (string) Str::uuid(),
            'floating_section_id' => FloatingSection::query()->inRandomOrder()->first()?->id ?? FloatingSection::factory()->create()->id,
            'master_schedule_id' => FloatingSectionSchedule::query()->inRandomOrder()->first()?->id,
        ];
    }
}
