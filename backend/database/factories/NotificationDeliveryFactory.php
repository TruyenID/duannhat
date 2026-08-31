<?php

namespace Database\Factories;

use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NotificationDelivery Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_recipient_id' => NotificationRecipient::query()->inRandomOrder()->first()?->id ?? NotificationRecipient::factory()->create()->id,
            'channel' => fake()->randomElement(['in_app', 'realtime', 'email', 'push']),
            'status' => fake()->randomElement(['pending', 'sent', 'delivered', 'failed', 'bounced', 'skipped']),
            'attempts' => fake()->sentence(),
            'last_attempted_at' => fake()->dateTime(),
            'sent_at' => fake()->dateTime(),
            'delivered_at' => fake()->dateTime(),
            'failed_at' => fake()->dateTime(),
            'error' => fake()->paragraphs(3, true),
            'provider_ref' => fake()->sentence(),
        ];
    }
}
