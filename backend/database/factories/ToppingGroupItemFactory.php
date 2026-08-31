<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ToppingGroupItem Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ToppingGroupItem>
 */
class ToppingGroupItemFactory extends Factory
{
    protected $model = ToppingGroupItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'topping_group_id' => fn () => ToppingGroup::factory()->create()->id,
            'product_id' => fn () => Product::factory()->create()->id,
            'sort_order' => 0,
        ];
    }
}
