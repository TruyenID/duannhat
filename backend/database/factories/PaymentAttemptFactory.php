<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentPolicyRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * PaymentAttempt Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'customer_order_id' => CustomerOrder::query()->inRandomOrder()->first()?->id ?? CustomerOrder::factory()->create()->id,
            'connection_id' => PaymentGatewayConnection::query()->inRandomOrder()->first()?->id ?? PaymentGatewayConnection::factory()->create()->id,
            'connection_option_id' => PaymentGatewayConnectionOption::query()->inRandomOrder()->first()?->id ?? PaymentGatewayConnectionOption::factory()->create()->id,
            'policy_revision_id' => PaymentPolicyRevision::query()->inRandomOrder()->first()?->id ?? PaymentPolicyRevision::factory()->create()->id,
            'device_id' => Device::query()->inRandomOrder()->first()?->id,
            'operation' => fake()->randomElement(['sale', 'authorize', 'capture', 'cancel']),
            'state' => fake()->randomElement(['prepared', 'provider_pending', 'action_required', 'processing', 'reconciliation_required', 'succeeded', 'failed', 'canceled', 'expired']),
            'provider' => fake()->randomElement(['internal', 'stripe', 'paypay', 'sbps']),
            'environment' => fake()->randomElement(['local', 'sandbox', 'test', 'live']),
            'owner_scope' => fake()->randomElement(['hq', 'franchise']),
            'operator_org_unit_id' => (string) Str::uuid(),
            'ownership_revision' => fake()->words(3, true),
            'channel' => fake()->randomElement(['customer_web', 'pos', 'kiosk', 'workstation', 'server_to_server']),
            'currency' => fake()->words(3, true),
            'amount_minor' => fake()->numberBetween(100, 10000),
            'idempotency_key' => Str::random(32),
            'request_fingerprint' => fake()->sentence(),
            'provider_request_key' => Str::random(32),
            'provider_object_id' => fake()->sentence(),
            'provider_status' => fake()->sentence(),
            'next_action' => [],
            'redacted_summary' => [],
            'error_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'retry_count' => fake()->numberBetween(0, 100),
            'next_reconciliation_at' => fake()->dateTime(),
            'version' => fake()->numberBetween(1, 1000),
            'prepared_at' => fake()->dateTime(),
            'dispatched_at' => fake()->dateTime(),
            'finalized_at' => fake()->dateTime(),
        ];
    }
}
