<?php

namespace Database\Factories;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\CustomerOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * CouponRedemption Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<CouponRedemption>
 */
class CouponRedemptionFactory extends Factory
{
    protected $model = CouponRedemption::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'discount_applied_amount' => fake()->randomFloat(2, 1, 10000),
            'coupon_snapshot' => [],
            'redeemed_at' => fake()->dateTime(),
            'released_at' => fake()->dateTime(),
            'redeemed_via' => fake()->words(3, true),
            'redeemed_by_user_id' => (string) Str::uuid(),
            'coupon_id' => Coupon::query()->inRandomOrder()->first()?->id ?? Coupon::factory()->create()->id,
            'customer_order_id' => CustomerOrder::query()->inRandomOrder()->first()?->id ?? CustomerOrder::factory()->create()->id,
            'customer_id' => Customer::query()->inRandomOrder()->first()?->id,
        ];
    }
}
