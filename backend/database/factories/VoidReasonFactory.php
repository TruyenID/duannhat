<?php

namespace Database\Factories;

use App\Models\VoidReason;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * VoidReason Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<VoidReason>
 */
class VoidReasonFactory extends Factory
{
    protected $model = VoidReason::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'brand_id' => (string) Str::uuid(),
            'label' => fake()->sentence(),
            'stock_effect' => fake()->randomElement(['waste', 'restock', 'none']),
            'requires_note' => fake()->boolean(),
            'is_active' => fake()->boolean(),
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}
