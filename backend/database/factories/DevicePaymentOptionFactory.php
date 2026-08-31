<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Device;
use App\Models\DevicePaymentOption;
use App\Models\Organization;
use App\Models\ShopPaymentOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * DevicePaymentOption Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<DevicePaymentOption>
 */
class DevicePaymentOptionFactory extends Factory
{
    protected $model = DevicePaymentOption::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'device_id' => Device::query()->inRandomOrder()->first()?->id ?? Device::factory()->create()->id,
            'shop_payment_option_id' => ShopPaymentOption::query()->inRandomOrder()->first()?->id ?? ShopPaymentOption::factory()->create()->id,
            'preference' => fake()->randomElement(['inherit', 'disabled']),
            'change_reason' => fake()->sentence(),
            'version' => fake()->numberBetween(1, 1000),
        ];
    }
}
