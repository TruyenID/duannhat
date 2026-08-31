<?php

namespace Database\Factories;

use App\Models\CustomerOrder;
use App\Models\OrderPaymentIntent;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * OrderPaymentIntent Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<OrderPaymentIntent>
 */
class OrderPaymentIntentFactory extends Factory
{
    protected $model = OrderPaymentIntent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'customer_order_id' => CustomerOrder::query()->inRandomOrder()->first()?->id ?? CustomerOrder::factory()->create()->id,
            'provider' => fake()->words(3, true),
            'intent_id' => fake()->sentence(),
        ];
    }
}
