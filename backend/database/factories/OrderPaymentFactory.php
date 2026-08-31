<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * OrderPayment Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<OrderPayment>
 */
class OrderPaymentFactory extends Factory
{
    protected $model = OrderPayment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_code' => fake()->unique()->regexify('[A-Z0-9]{10}'),
            'amount' => fake()->randomFloat(2, 100, 1000),
            'tip_amount' => 0,
            'status' => 'pending',
            'paid_at' => null,
            'tendered_amount' => null,
            'change_amount' => null,
            'reference_no' => null,
            'note' => fake()->optional()->sentence(),
            'refund_of_id' => null,
            'received_by_id' => null,
            'payment_method_id' => PaymentMethod::query()->inRandomOrder()->first()?->id ?? PaymentMethod::factory()->create()->id,
            'customer_order_id' => CustomerOrder::query()->inRandomOrder()->first()?->id ?? CustomerOrder::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }

    /**
     * Payment succeeded (confirmed and settled).
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'succeeded',
            'paid_at' => now()->subMinutes(fake()->numberBetween(1, 30)),
        ]);
    }

    /**
     * Payment failed (declined or timed out).
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'paid_at' => null,
        ]);
    }

    /**
     * Payment was refunded after succeeding.
     */
    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'refunded',
            'paid_at' => now()->subMinutes(fake()->numberBetween(30, 120)),
        ]);
    }

    /**
     * Cash payment — includes tendered amount and change calculation.
     */
    public function cash(): static
    {
        return $this->state(function (array $attributes) {
            $amount = $attributes['amount'] ?? fake()->randomFloat(2, 100, 1000);
            $tendered = ceil($amount / 100) * 100 + fake()->randomElement([0, 100, 500, 1000]);

            return [
                'tendered_amount' => $tendered,
                'change_amount' => max(0, $tendered - $amount),
            ];
        });
    }
}
