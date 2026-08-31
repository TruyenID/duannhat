<?php

namespace Database\Factories;

use App\Models\CustomerOrderItem;
use App\Models\OrderItemTopping;
use App\Models\ProductSku;
use App\Models\ToppingGroupItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * OrderItemTopping Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<OrderItemTopping>
 */
class OrderItemToppingFactory extends Factory
{
    protected $model = OrderItemTopping::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_order_item_id' => CustomerOrderItem::query()->inRandomOrder()->first()?->id ?? CustomerOrderItem::factory()->create()->id,
            'topping_group_item_id' => ToppingGroupItem::query()->inRandomOrder()->first()?->id ?? ToppingGroupItem::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id,
            'quantity' => fake()->numberBetween(0, 100),
            'status' => 'pending',
            'unit_price' => fake()->randomFloat(2, 1, 10000),
            'note' => fake()->sentence(),
        ];
    }
}
