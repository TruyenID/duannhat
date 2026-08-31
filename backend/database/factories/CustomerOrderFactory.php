<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * CustomerOrder Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<CustomerOrder>
 */
class CustomerOrderFactory extends Factory
{
    protected $model = CustomerOrder::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'order_type' => 'dine_in',
            'status' => 'open',
            'subtotal' => fake()->randomFloat(2, 1, 10000),
            'total_amount' => fake()->randomFloat(2, 1, 10000),
            'paid_amount' => 0,
            'total_tip' => 0,
            'opened_at' => now(),
            'checkout_at' => null,
            'closed_at' => null,
            'voided_at' => null,
            'void_reason' => null,
            'note' => fake()->optional()->sentence(),
            'stock_out_transaction_id' => null,
            'created_by_id' => (string) Str::uuid(),
            'customer_id' => Customer::query()->inRandomOrder()->first()?->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }

    /**
     * Order is open (just seated / started).
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'opened_at' => now(),
            'checkout_at' => null,
            'closed_at' => null,
            'voided_at' => null,
            'void_reason' => null,
        ]);
    }

    /**
     * Order is in the dining phase (items ordered, eating).
     */
    public function dining(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'dining',
            'opened_at' => now()->subMinutes(fake()->numberBetween(10, 60)),
            'checkout_at' => null,
            'closed_at' => null,
            'voided_at' => null,
            'void_reason' => null,
        ]);
    }

    /**
     * Order is at the checkout stage (bill requested).
     */
    public function checkout(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'checkout',
            'opened_at' => now()->subMinutes(fake()->numberBetween(30, 120)),
            'checkout_at' => now()->subMinutes(fake()->numberBetween(1, 10)),
            'closed_at' => null,
            'voided_at' => null,
            'void_reason' => null,
        ]);
    }

    /**
     * Order is actively being paid.
     */
    public function paying(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paying',
            'opened_at' => now()->subMinutes(fake()->numberBetween(30, 120)),
            'checkout_at' => now()->subMinutes(fake()->numberBetween(1, 10)),
            'closed_at' => null,
            'voided_at' => null,
            'void_reason' => null,
        ]);
    }

    /**
     * Order is closed (payment received).
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'opened_at' => now()->subMinutes(fake()->numberBetween(60, 180)),
            'checkout_at' => now()->subMinutes(fake()->numberBetween(10, 30)),
            'closed_at' => now()->subMinutes(fake()->numberBetween(1, 10)),
            'voided_at' => null,
            'void_reason' => null,
        ]);
    }

    /**
     * Order is voided (cancelled before payment).
     */
    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'voided',
            'voided_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
            'void_reason' => fake()->sentence(),
            'checkout_at' => null,
            'closed_at' => null,
        ]);
    }
}
