<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Coupon Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'name' => fake()->sentence(3),
            'description' => fake()->paragraphs(3, true),

            // Every field an applicability check reads is DETERMINISTIC and
            // permissive, so the default coupon is one that actually applies.
            //
            // These used to be randomised, which made the factory a coin flip
            // against any gate that compares them to order data. The worst was
            // `min_order_subtotal` at randomFloat(1, 10000): a test ordering
            // 5000 got its coupon silently dropped ~50% of the time, and the
            // failure surfaced as an unrelated "wrong tax" assertion. The
            // others were no better — `times_used` (1..1000) against
            // `usage_limit_total` (18..80) meant a coupon was frequently born
            // exhausted, `valid_until` could precede `valid_from`, and a random
            // `paused` status is rejected outright by CouponService.
            //
            // A test that wants a gate to BITE must now say so explicitly
            // (min_order_subtotal, times_used, status, ...). That reads as
            // intent, and it cannot flake.
            'discount_type' => 'fixed',
            'discount_value' => 500,
            'max_discount_cap' => null,
            'min_order_subtotal' => 0,
            'usage_limit_total' => null, // nullable = no cap
            'usage_limit_per_customer' => 0, // NOT NULL in schema; 0 is the "unlimited" sentinel
            'times_used' => 0,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addYear(),
            'status' => 'draft', // the only non-paused case; `paused` is a rejection state
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
