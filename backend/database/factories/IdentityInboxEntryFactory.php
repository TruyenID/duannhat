<?php

namespace Database\Factories;

use App\Models\IdentityInboxEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * IdentityInboxEntry Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<IdentityInboxEntry>
 */
class IdentityInboxEntryFactory extends Factory
{
    protected $model = IdentityInboxEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'resource_type' => fake()->sentence(),
            'resource_id' => fake()->sentence(),
            'event_type' => fake()->sentence(),
            'sequence' => fake()->numberBetween(1, 1000),
            'payload' => [],
            'occurred_at' => fake()->dateTime(),
            'received_at' => fake()->dateTime(),
            'applied_at' => fake()->dateTime(),
            'apply_error' => fake()->paragraphs(3, true),
        ];
    }
}
