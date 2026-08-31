<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\ProductSku;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * StockTransferItem Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<StockTransferItem>
 */
class StockTransferItemFactory extends Factory
{
    protected $model = StockTransferItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_transfer_id' => StockTransfer::query()->inRandomOrder()->first()?->id ?? StockTransfer::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id,
            'material_id' => Material::query()->inRandomOrder()->first()?->id,
            'sent_quantity' => fake()->randomFloat(2, 1, 10000),
            'sent_base_quantity' => fake()->randomFloat(2, 1, 10000),
            'received_quantity' => fake()->randomFloat(2, 1, 10000),
            'received_base_quantity' => fake()->randomFloat(2, 1, 10000),
            'unit' => fake()->words(3, true),
            'note' => fake()->paragraphs(3, true),
        ];
    }
}
