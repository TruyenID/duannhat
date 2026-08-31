<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\ProductSku;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * StockTransactionItem Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<StockTransactionItem>
 */
class StockTransactionItemFactory extends Factory
{
    protected $model = StockTransactionItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_transaction_id' => StockTransaction::query()->inRandomOrder()->first()?->id ?? StockTransaction::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id,
            'material_id' => Material::query()->inRandomOrder()->first()?->id,
            'quantity' => fake()->randomFloat(2, 1, 10000),
            'base_quantity' => fake()->randomFloat(2, 1, 10000),
            'unit' => fake()->words(3, true),
            'unit_price' => fake()->randomFloat(2, 1, 10000),
            'note' => fake()->paragraphs(3, true),
        ];
    }
}
