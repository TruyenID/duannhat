<?php

namespace Database\Factories;

use App\Models\Menu;
use App\Models\MenuSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * MenuSchedule Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MenuSchedule>
 */
class MenuScheduleFactory extends Factory
{
    protected $model = MenuSchedule::class;

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
            'is_active' => fake()->boolean(),
            'priority' => fake()->numberBetween(1, 1000),
            'created_by_id' => (string) Str::uuid(),
            'menu_id' => Menu::query()->inRandomOrder()->first()?->id ?? Menu::factory()->create()->id,
        ];
    }
}
