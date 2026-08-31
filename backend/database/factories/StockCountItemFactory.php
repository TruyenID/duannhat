<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\ProductSku;
use App\Models\StockCount;
use App\Models\StockCountItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * StockCountItem Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<StockCountItem>
 */
class StockCountItemFactory extends Factory
{
    protected $model = StockCountItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_count_id' => StockCount::query()->inRandomOrder()->first()?->id ?? StockCount::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id,
            'material_id' => Material::query()->inRandomOrder()->first()?->id,
            'system_quantity' => fake()->randomFloat(2, 1, 10000),
            'counted_quantity' => fake()->randomFloat(2, 1, 10000),
            'difference' => fake()->randomFloat(2, 1, 10000),
            'unit' => fake()->words(3, true),
            'note' => fake()->paragraphs(3, true),
        ];
    }
}
