<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\Organization;
use App\Omnify\Enums\NotificationPriorityEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Notification Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * Sensible defaults for a system event with no actor + no subject.
     * Tests that need specific shapes use the `withActor` / `withSubject`
     * states below.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id
                ?? Organization::factory()->create()->id,
            'actor_type' => null,
            'actor_id' => null,
            'type' => 'system.test',
            'template_key' => 'system.test',
            'params' => [],
            'subject_type' => null,
            'subject_id' => null,
            'priority' => NotificationPriorityEnum::Normal->value,
            'aggregation_key' => null,
            'idempotency_key' => null,
            'audience_id' => null,
            'scheduled_for' => null,
            'is_dispatched' => true,
        ];
    }

    /**
     * Set both actor columns from a morphable model (User, Device, …).
     */
    public function withActor(Model $actor): static
    {
        return $this->state(fn () => [
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
        ]);
    }

    /**
     * Explicit system event — both actor columns null.
     */
    public function withoutActor(): static
    {
        return $this->state(fn () => [
            'actor_type' => null,
            'actor_id' => null,
        ]);
    }

    /**
     * Set both subject columns from a morphable model (Recipe, CustomerOrder,
     * StockAlert, …).
     */
    public function withSubject(Model $subject): static
    {
        return $this->state(fn () => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    /** Set priority via the typed enum (avoids stringly-typed callers). */
    public function priority(NotificationPriorityEnum $priority): static
    {
        return $this->state(fn () => ['priority' => $priority->value]);
    }

    /** Set a stable idempotency key (for retry dedup tests). */
    public function idempotent(string $key): static
    {
        return $this->state(fn () => ['idempotency_key' => $key]);
    }
}
