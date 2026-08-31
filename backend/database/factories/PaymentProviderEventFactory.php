<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentProviderEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * PaymentProviderEvent Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PaymentProviderEvent>
 */
class PaymentProviderEventFactory extends Factory
{
    protected $model = PaymentProviderEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'connection_id' => PaymentGatewayConnection::query()->inRandomOrder()->first()?->id ?? PaymentGatewayConnection::factory()->create()->id,
            'provider' => fake()->randomElement(['internal', 'stripe', 'paypay', 'sbps']),
            'environment' => fake()->randomElement(['local', 'sandbox', 'test', 'live']),
            'state' => fake()->randomElement(['received_verified', 'queued', 'processing', 'retryable', 'succeeded', 'dead_letter', 'operator_resolved']),
            'provider_event_id' => fake()->sentence(),
            'event_type' => fake()->sentence(),
            'provider_object_id' => fake()->sentence(),
            'payload_hash' => fake()->sentence(),
            'signature_version' => fake()->sentence(),
            'redacted_payload' => [],
            'outcome' => fake()->sentence(),
            'delivery_count' => fake()->numberBetween(0, 100),
            'processing_attempts' => fake()->numberBetween(1, 1000),
            'lease_token' => Str::random(32),
            'lease_expires_at' => fake()->dateTime(),
            'next_retry_at' => fake()->dateTime(),
            'last_error_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'redacted_error' => [],
            'received_at' => fake()->dateTime(),
            'verified_at' => fake()->dateTime(),
            'processed_at' => fake()->dateTime(),
            'operator_resolution' => fake()->sentence(),
            'operator_resolved_at' => fake()->dateTime(),
        ];
    }
}
