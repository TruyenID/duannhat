<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ProductReview Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ProductReview>
 */
class ProductReviewFactory extends Factory
{
    protected $model = ProductReview::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::query()->inRandomOrder()->first()?->id ?? Product::factory()->create()->id,
            'customer_order_id' => CustomerOrder::query()->inRandomOrder()->first()?->id ?? CustomerOrder::factory()->create()->id,
            'customer_order_item_id' => CustomerOrderItem::query()->inRandomOrder()->first()?->id ?? CustomerOrderItem::factory()->create()->id,
            'customer_id' => Customer::query()->inRandomOrder()->first()?->id,
            'sentiment' => fake()->randomElement(['up', 'down']),
            'comment' => fake()->paragraphs(3, true),
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
        ];
    }
}
