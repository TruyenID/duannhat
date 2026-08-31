<?php

namespace Database\Factories;

use App\Models\OrderCondition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * OrderCondition Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<OrderCondition>
 */
class OrderConditionFactory extends Factory
{
    protected $model = OrderCondition::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conditionable_type' => fake()->words(3, true),
            'conditionable_id' => (string) Str::uuid(),
            'type' => fake()->words(3, true),
            'source' => fake()->words(3, true),
            'label' => fake()->sentence(),
            'rate' => fake()->randomFloat(2, 1, 10000),
            'amount' => fake()->randomFloat(2, 1, 10000),
            'currency_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'meta' => [],
        ];
    }
}
