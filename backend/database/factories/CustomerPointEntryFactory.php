<?php

namespace Database\Factories;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerPointEntry;
use App\Models\Organization;
use App\Models\PointReward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CustomerPointEntry Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<CustomerPointEntry>
 */
class CustomerPointEntryFactory extends Factory
{
    protected $model = CustomerPointEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'points' => fake()->numberBetween(1, 1000),
            'kind' => fake()->randomElement(['earn', 'redeem', 'revoke', 'adjust', 'expire']),
            'note' => fake()->sentence(),
            'expires_at' => fake()->dateTime(),
            'customer_id' => Customer::query()->inRandomOrder()->first()?->id ?? Customer::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id,
            'customer_order_id' => CustomerOrder::query()->inRandomOrder()->first()?->id,
            'coupon_id' => Coupon::query()->inRandomOrder()->first()?->id,
            'point_reward_id' => PointReward::query()->inRandomOrder()->first()?->id,
        ];
    }
}
