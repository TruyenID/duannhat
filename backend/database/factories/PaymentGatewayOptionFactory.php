<?php

namespace Database\Factories;

use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PaymentGatewayOption Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PaymentGatewayOption>
 */
class PaymentGatewayOptionFactory extends Factory
{
    protected $model = PaymentGatewayOption::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => PaymentGatewayProvider::query()->inRandomOrder()->first()?->id ?? PaymentGatewayProvider::factory()->create()->id,
            'code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'name' => fake()->sentence(3),
            'integration_product' => fake()->sentence(),
            'api_version' => fake()->words(3, true),
            'rail' => fake()->randomElement(['cash', 'card', 'wallet', 'qr', 'e_money', 'bank_transfer']),
            'method_type' => fake()->sentence(),
            'brands' => [],
            'channels' => [],
            'device_classes' => [],
            'currencies' => [],
            'workflows' => [],
            'operations' => [],
            'limits' => [],
            'recovery' => [],
            'merchant_identity_requirements' => [],
            'revision' => fake()->numberBetween(1, 1000),
            // #1868 — an option built with no arguments must be one that is
            // CURRENTLY IN FORCE. `fake()->dateTime()` draws from 1970→now, so
            // `effective_to` was ALWAYS in the past: measured 200/200 past,
            // 0/200 future. Once #1866 taught
            // `PosEffectivePaymentOptionEnricher::internalTenderMethodIds()` to
            // filter `effective_to > now`, every default-built option became
            // invisible to it — deterministically, not flakily (probed 3/3).
            //
            // A test that seeds an option and then asserts on the enricher
            // would go GREEN because the row was never considered. That is the
            // shape `CLAUDE.md` warns about: a check that answers "yes" to
            // "is this covered?" without covering it.
            //
            // `effective_to = null` means "open-ended", which is what a live
            // catalog row looks like. A test that wants an expired window now
            // has to say so — and saying so is the point.
            'effective_from' => fake()->dateTimeBetween('-2 years', '-1 day'),
            'effective_to' => null,
            'is_active' => fake()->boolean(),
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}
