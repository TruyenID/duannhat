<?php

namespace Database\Factories;

use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ProductOptionValue Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ProductOptionValue>
 */
class ProductOptionValueFactory extends Factory
{
    protected $model = ProductOptionValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'option_id' => fn () => ProductOption::query()->inRandomOrder()->first()?->id ?? ProductOption::factory()->create()->id,
            'value' => fake()->unique()->slug(1),
            'label' => fake()->word(),
            'position' => 0,
            'is_active' => true,
        ];
    }
}
