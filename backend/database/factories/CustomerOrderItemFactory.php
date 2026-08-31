<?php

namespace Database\Factories;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CustomerOrderItem Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<CustomerOrderItem>
 */
class CustomerOrderItemFactory extends Factory
{
    protected $model = CustomerOrderItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantity' => fake()->numberBetween(1, 5),
            'unit_price' => fake()->randomFloat(2, 100, 5000),
            // #2617 (ruling #2132 §B) — original_unit_price là dấu vết định
            // hình giá BẮT BUỘC (NOT NULL) trên mọi dòng: bằng chính unit_price
            // khi không cơ chế nào hạ giá. Cùng khuôn với tax_rate (#2188/#2411).
            // Test dựng dòng khuyến mãi thì override cả hai cột.
            'original_unit_price' => fn (array $attributes) => $attributes['unit_price'],
            'subtotal' => fake()->randomFloat(2, 100, 10000),
            // #2188 — every creation path stamps the per-line tax snapshot; a
            // NULL tax_rate is broken input the engine drops with a warning.
            // Authored default: explicit 0% (tests that price tax set a rate).
            'tax_rate' => 0,
            'status' => 'pending',
            'served_at' => null,
            'voided_at' => null,
            'void_reason' => null,
            'note' => fake()->optional()->sentence(),
            'customer_order_id' => CustomerOrder::query()->inRandomOrder()->first()?->id ?? CustomerOrder::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id ?? ProductSku::factory()->create()->id,
        ];
    }

    /**
     * Item is being prepared in the kitchen.
     */
    public function preparing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'preparing',
            'served_at' => null,
            'voided_at' => null,
            'void_reason' => null,
        ]);
    }

    /**
     * Item is ready to be served.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ready',
            'served_at' => null,
            'voided_at' => null,
            'void_reason' => null,
        ]);
    }

    /**
     * Item has been served to the customer.
     */
    public function served(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'served',
            'served_at' => now()->subMinutes(fake()->numberBetween(1, 30)),
            'voided_at' => null,
            'void_reason' => null,
        ]);
    }

    /**
     * Item has been voided (cancelled after ordering).
     */
    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'voided',
            'voided_at' => now()->subMinutes(fake()->numberBetween(1, 30)),
            'void_reason' => fake()->sentence(),
            'served_at' => null,
        ]);
    }
}
