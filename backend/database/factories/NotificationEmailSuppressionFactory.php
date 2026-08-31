<?php

namespace Database\Factories;

use App\Models\NotificationEmailSuppression;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NotificationEmailSuppression Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<NotificationEmailSuppression>
 */
class NotificationEmailSuppressionFactory extends Factory
{
    protected $model = NotificationEmailSuppression::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'email' => fake()->unique()->safeEmail(),
            'reason' => fake()->words(3, true),
            'source_provider' => fake()->words(3, true),
            'suppressed_at' => fake()->dateTime(),
            'un_suppressed_at' => fake()->dateTime(),
        ];
    }
}
