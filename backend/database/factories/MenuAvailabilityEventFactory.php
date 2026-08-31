<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\MenuAvailabilityEvent;
use App\Models\MenuProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MenuAvailabilityEvent Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MenuAvailabilityEvent>
 */
class MenuAvailabilityEventFactory extends Factory
{
    protected $model = MenuAvailabilityEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'menu_product_id' => MenuProduct::query()->inRandomOrder()->first()?->id,
            'entity_type' => fake()->randomElement(['menu_product', 'menu_product_sku', 'topping_item']),
            'entity_id' => fake()->words(3, true),
            'is_active' => fake()->boolean(),
            'reason' => fake()->sentence(),
            'source' => fake()->randomElement(['pos', 'workstation', 'admin']),
            'occurred_at' => fake()->dateTime(),
            'acted_by_user_id' => User::query()->inRandomOrder()->first()?->id,
            'actor_name' => fake()->name(),
        ];
    }
}
