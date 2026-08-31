<?php

namespace Database\Factories;

use App\Models\ProductToppingGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ProductToppingGroup Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ProductToppingGroup>
 */
class ProductToppingGroupFactory extends Factory
{
    protected $model = ProductToppingGroup::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}
