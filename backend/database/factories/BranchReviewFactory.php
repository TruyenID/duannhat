<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchReview;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * BranchReview Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<BranchReview>
 */
class BranchReviewFactory extends Factory
{
    protected $model = BranchReview::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'customer_order_id' => CustomerOrder::query()->inRandomOrder()->first()?->id ?? CustomerOrder::factory()->create()->id,
            'customer_id' => Customer::query()->inRandomOrder()->first()?->id,
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->optional(0.5)->sentence(),
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
        ];
    }
}
