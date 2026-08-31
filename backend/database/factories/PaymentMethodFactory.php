<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PaymentMethod Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->regexify('[a-z_]{6,12}'),
            'name' => fake()->words(2, true),
            'is_auto_confirm' => false,
            'requires_tendered' => false,
            'is_active' => true,
            'sort_order' => 0,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }

    /**
     * Cash payment — auto-confirms and requires tendered amount.
     */
    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'cash',
            'is_auto_confirm' => true,
            'requires_tendered' => true,
        ]);
    }

    /**
     * Card payment (credit/debit).
     */
    public function card(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'card',
            'is_auto_confirm' => false,
            'requires_tendered' => false,
        ]);
    }

    /**
     * Card swiped on the bank's standalone payment terminal — approval happens
     * out-of-band, so POS records the sale straight to `succeeded` (there is no
     * POS confirm route) and asks for no tendered amount.
     */
    public function cardTerminal(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'card_terminal',
            'type' => 'card',
            'is_auto_confirm' => true,
            'requires_tendered' => false,
        ]);
    }

    /**
     * Bank transfer payment.
     */
    public function transfer(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'transfer',
            'is_auto_confirm' => false,
            'requires_tendered' => false,
        ]);
    }

    /**
     * E-wallet payment (QR code / mobile wallet).
     */
    public function eWallet(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'e_wallet',
            'is_auto_confirm' => false,
            'requires_tendered' => false,
        ]);
    }
}
