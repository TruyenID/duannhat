<?php

namespace Database\Factories;

use App\Models\OrderMoneyOverwrite;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * OrderMoneyOverwrite Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<OrderMoneyOverwrite>
 */
class OrderMoneyOverwriteFactory extends Factory
{
    protected $model = OrderMoneyOverwrite::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => (string) Str::uuid(),
            'local_id' => fake()->numberBetween(1, 1000),
            'branch_id' => (string) Str::uuid(),
            'organization_id' => (string) Str::uuid(),
            'order_id' => (string) Str::uuid(),
            'occurred_at' => fake()->dateTime(),
            'paid_locally' => fake()->numberBetween(1, 1000),
            'total_amount_local' => fake()->numberBetween(100, 10000),
            'total_amount_cloud' => fake()->numberBetween(100, 10000),
            'subtotal_local' => fake()->numberBetween(1, 1000),
            'subtotal_cloud' => fake()->numberBetween(1, 1000),
            'tax_amount_local' => fake()->numberBetween(100, 10000),
            'tax_amount_cloud' => fake()->numberBetween(100, 10000),
            'service_charge_local' => fake()->numberBetween(1, 1000),
            'service_charge_cloud' => fake()->numberBetween(1, 1000),
            'discount_amount_local' => fake()->numberBetween(0, 100),
            'discount_amount_cloud' => fake()->numberBetween(0, 100),
        ];
    }
}
