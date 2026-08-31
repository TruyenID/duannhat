<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ProductOption Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ProductOption>
 */
class ProductOptionFactory extends Factory
{
    protected $model = ProductOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => fn () => Product::query()->inRandomOrder()->first()?->id ?? Product::factory()->create()->id,
            'key' => fake()->unique()->randomElement(['color', 'size', 'material']),
            'name' => fake()->word(),
            'position' => 1,
            'is_active' => true,
        ];
    }

    /**
     * State: set position explicitly.
     */
    public function position(int $position): static
    {
        $keys = [1 => 'color', 2 => 'size', 3 => 'material'];

        return $this->state(fn () => [
            'position' => $position,
            'key' => $keys[$position] ?? fake()->unique()->word(),
        ]);
    }
}
