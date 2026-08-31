<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\MenuPromotion;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MenuPromotion Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MenuPromotion>
 */
class MenuPromotionFactory extends Factory
{
    protected $model = MenuPromotion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->paragraphs(3, true),
            'discount_percent' => fake()->randomFloat(2, 1, 10000),
            'applies_to' => fake()->randomElement(['all_items', 'categories', 'products', 'mixed']),
            // Always-open daily window. A random from/to made every promotion
            // fixture apply only when the clock happened to fall inside it, so
            // any test that did not pin these two columns was flaky by
            // construction. Tests that exercise the daily window override them.
            'daily_time_from' => '00:00:00',
            'daily_time_to' => '23:59:59',
            'weekdays' => [],
            'valid_from' => fake()->dateTime(),
            'valid_until' => fake()->dateTime(),
            'stacking_mode' => fake()->randomElement(['exclusive_with_coupons', 'stackable_with_coupons']),
            'is_active' => fake()->boolean(),
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
