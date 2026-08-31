<?php

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NotificationPreference Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::query()->inRandomOrder()->first()?->id ?? User::factory()->create()->id,
            'type' => fake()->sentence(),
            'channel' => fake()->words(3, true),
            'enabled' => fake()->boolean(),
            'master_mute' => fake()->boolean(),
            'quiet_from' => fake()->words(3, true),
            'quiet_to' => fake()->words(3, true),
            'quiet_timezone' => fake()->sentence(),
        ];
    }
}
