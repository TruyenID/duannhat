<?php

namespace Database\Factories;

use App\Models\FloatingSectionProduct;
use App\Models\FloatingSectionProductSku;
use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * FloatingSectionProductSku Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<FloatingSectionProductSku>
 */
class FloatingSectionProductSkuFactory extends Factory
{
    protected $model = FloatingSectionProductSku::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'floating_section_product_id' => FloatingSectionProduct::query()->inRandomOrder()->first()?->id ?? FloatingSectionProduct::factory()->create()->id,
            'product_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id ?? ProductSku::factory()->create()->id,
            'is_active' => fake()->boolean(),
        ];
    }
}
