<?php

namespace Database\Factories;

use App\Models\NotificationDigestPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NotificationDigestPreference Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<NotificationDigestPreference>
 */
class NotificationDigestPreferenceFactory extends Factory
{
    protected $model = NotificationDigestPreference::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::query()->inRandomOrder()->first()?->id ?? User::factory()->create()->id,
            'cadence' => fake()->words(3, true),
            'delivery_time' => fake()->words(3, true),
            'timezone' => fake()->sentence(),
            'weekday' => fake()->numberBetween(1, 1000),
            'include_priorities' => [],
            'last_sent_at' => fake()->dateTime(),
        ];
    }
}
