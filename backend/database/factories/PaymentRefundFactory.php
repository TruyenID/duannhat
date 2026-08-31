<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentRefund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * PaymentRefund Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PaymentRefund>
 */
class PaymentRefundFactory extends Factory
{
    protected $model = PaymentRefund::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'payment_attempt_id' => PaymentAttempt::query()->inRandomOrder()->first()?->id ?? PaymentAttempt::factory()->create()->id,
            'connection_id' => PaymentGatewayConnection::query()->inRandomOrder()->first()?->id ?? PaymentGatewayConnection::factory()->create()->id,
            'state' => fake()->randomElement(['prepared', 'submitted', 'pending', 'reconciliation_required', 'succeeded', 'failed', 'canceled']),
            'provider' => fake()->randomElement(['internal', 'stripe', 'paypay', 'sbps']),
            'environment' => fake()->randomElement(['local', 'sandbox', 'test', 'live']),
            'currency' => fake()->words(3, true),
            'amount_minor' => fake()->numberBetween(100, 10000),
            'idempotency_key' => Str::random(32),
            'request_fingerprint' => fake()->sentence(),
            'provider_request_key' => Str::random(32),
            'provider_refund_id' => fake()->sentence(),
            'provider_status' => fake()->sentence(),
            'reason_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'redacted_summary' => [],
            'error_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'retry_count' => fake()->numberBetween(0, 100),
            'next_reconciliation_at' => fake()->dateTime(),
            'version' => fake()->numberBetween(1, 1000),
            'prepared_at' => fake()->dateTime(),
            'submitted_at' => fake()->dateTime(),
            'finalized_at' => fake()->dateTime(),
        ];
    }
}
