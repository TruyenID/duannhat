<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ShopOrderSetting Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ShopOrderSetting>
 */
class ShopOrderSettingFactory extends Factory
{
    protected $model = ShopOrderSetting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'default_order_item_status' => fake()->words(3, true),
            'enable_quick_order' => false,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
