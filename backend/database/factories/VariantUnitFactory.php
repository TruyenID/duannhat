<?php

namespace Database\Factories;

use App\Models\ProductSku;
use App\Models\VariantUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * VariantUnit Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<VariantUnit>
 */
class VariantUnitFactory extends Factory
{
    protected $model = VariantUnit::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id ?? ProductSku::factory()->create()->id,
            'unit' => fake()->words(3, true),
            'ratio' => fake()->randomFloat(2, 1, 10000),
            'sku' => fake()->words(3, true),
            'barcode' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'price' => fake()->randomFloat(2, 1, 10000),
            'is_base' => fake()->boolean(),
            'is_sellable' => fake()->boolean(),
        ];
    }
}
