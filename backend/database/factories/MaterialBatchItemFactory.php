<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialBatchItem;
use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MaterialBatchItem Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MaterialBatchItem>
 */
class MaterialBatchItemFactory extends Factory
{
    protected $model = MaterialBatchItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_batch_id' => MaterialBatch::query()->inRandomOrder()->first()?->id ?? MaterialBatch::factory()->create()->id,
            'component_type' => fake()->randomElement(['material', 'variant']),
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id,
            'material_id' => Material::query()->inRandomOrder()->first()?->id,
            'planned_quantity' => fake()->randomFloat(2, 1, 10000),
            'actual_quantity' => fake()->randomFloat(2, 1, 10000),
            'unit' => fake()->words(3, true),
            'stock_available' => fake()->randomFloat(2, 1, 10000),
        ];
    }
}
