<?php

namespace Database\Factories;

use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentGatewayOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PaymentGatewayConnectionOption Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PaymentGatewayConnectionOption>
 */
class PaymentGatewayConnectionOptionFactory extends Factory
{
    protected $model = PaymentGatewayConnectionOption::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => PaymentGatewayConnection::query()->inRandomOrder()->first()?->id ?? PaymentGatewayConnection::factory()->create()->id,
            'option_id' => PaymentGatewayOption::query()->inRandomOrder()->first()?->id ?? PaymentGatewayOption::factory()->create()->id,
            'verification_state' => fake()->randomElement(['unknown', 'contract_required', 'certification_required', 'verified', 'restricted']),
            'approved_currencies' => [],
            'approved_channels' => [],
            'approved_operations' => [],
            'approved_limits' => [],
            'merchant_configuration' => [],
            'evidence_ref' => fake()->sentence(),
            'capability_revision' => fake()->numberBetween(1, 1000),
            'effective_from' => fake()->dateTime(),
            'effective_to' => fake()->dateTime(),
            'verified_at' => fake()->dateTime(),
            'is_enabled' => fake()->boolean(),
        ];
    }
}
