<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\NotificationRuleFiring;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NotificationRuleFiring Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<NotificationRuleFiring>
 */
class NotificationRuleFiringFactory extends Factory
{
    protected $model = NotificationRuleFiring::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rule_id' => NotificationRule::query()->inRandomOrder()->first()?->id ?? NotificationRule::factory()->create()->id,
            'notification_id' => Notification::query()->inRandomOrder()->first()?->id,
            'model_type' => fake()->sentence(),
            'model_id' => fake()->sentence(),
            'fired_at' => fake()->dateTime(),
            'outcome' => fake()->words(3, true),
            'evaluation_trace' => [],
            'error_message' => fake()->paragraphs(3, true),
        ];
    }
}
