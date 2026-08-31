<?php

namespace Database\Factories;

use App\Models\OrderCodeCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * OrderCodeCounter Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<OrderCodeCounter>
 */
class OrderCodeCounterFactory extends Factory
{
    protected $model = OrderCodeCounter::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'year' => fake()->year(),
            'next_value' => fake()->numberBetween(1, 1000),
        ];
    }
}
