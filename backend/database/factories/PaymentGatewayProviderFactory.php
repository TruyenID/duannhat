<?php

namespace Database\Factories;

use App\Models\PaymentGatewayProvider;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PaymentGatewayProvider Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PaymentGatewayProvider>
 */
class PaymentGatewayProviderFactory extends Factory
{
    protected $model = PaymentGatewayProvider::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // `code` is a UNIQUE column backed by PaymentGatewayProviderCodeEnum,
            // and every value of that enum is already seeded — so this factory
            // cannot produce a NEW provider at all. It used to pick one of the
            // four at random, which made it succeed or fail BY LUCK depending on
            // which seeded row it happened to collide with, and any test using it
            // inherited that coin flip. A fixed value at least fails predictably.
            // Callers must supply their own code (or reuse the seeded provider).
            'code' => PaymentGatewayProviderCodeEnum::Internal->value,
            'name' => fake()->sentence(3),
            'description' => fake()->paragraphs(3, true),
            // Not a coin flip: a test that does not pin this flag should not
            // pass or fail depending on it.
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}
