<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * NotificationRecipient Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<NotificationRecipient>
 */
class NotificationRecipientFactory extends Factory
{
    protected $model = NotificationRecipient::class;

    /**
     * Default: unread, unseen, not dismissed — fan-out to a fresh User.
     * Tests that want a Device recipient use the `forRecipient($device)` state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory()->create();

        return [
            'notification_id' => Notification::query()->inRandomOrder()->first()?->id
                ?? Notification::factory()->create()->id,
            'recipient_type' => $user->getMorphClass(),
            'recipient_id' => $user->getKey(),
            'seen_at' => null,
            'read_at' => null,
            'dismissed_at' => null,
            'resolved_via' => null,
        ];
    }

    /**
     * Set recipient columns from any morphable model (User, Device, …).
     */
    public function forRecipient(Model $recipient): static
    {
        return $this->state(fn () => [
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
        ]);
    }

    /** Row that has been read (and by implication seen). */
    public function read(): static
    {
        return $this->state(fn () => [
            'seen_at' => now(),
            'read_at' => now(),
            'dismissed_at' => null,
        ]);
    }

    /** Row the user has archived (dismissed). `seen_at` is also set. */
    public function dismissed(): static
    {
        return $this->state(fn () => [
            'seen_at' => now(),
            'read_at' => null,
            'dismissed_at' => now(),
        ]);
    }

    /** Explicit unread — resets all interaction columns to null. */
    public function unread(): static
    {
        return $this->state(fn () => [
            'seen_at' => null,
            'read_at' => null,
            'dismissed_at' => null,
        ]);
    }
}
