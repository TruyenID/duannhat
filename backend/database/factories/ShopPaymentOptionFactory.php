<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayOption;
use App\Models\ShopPaymentOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ShopPaymentOption Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ShopPaymentOption>
 */
class ShopPaymentOptionFactory extends Factory
{
    protected $model = ShopPaymentOption::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'option_id' => PaymentGatewayOption::query()->inRandomOrder()->first()?->id ?? PaymentGatewayOption::factory()->create()->id,
            'connection_id' => PaymentGatewayConnection::query()->inRandomOrder()->first()?->id,
            'preference' => fake()->randomElement(['inherit', 'enabled', 'disabled', 'blocked']),
            'change_reason' => fake()->sentence(),
            'version' => fake()->numberBetween(1, 1000),
        ];
    }
}
