<?php

namespace Database\Factories;

use App\Models\PrintImageAsset;
use App\Models\PrintImageRaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PrintImageRaster Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PrintImageRaster>
 */
class PrintImageRasterFactory extends Factory
{
    protected $model = PrintImageRaster::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => PrintImageAsset::query()->inRandomOrder()->first()?->id ?? PrintImageAsset::factory()->create()->id,
            'max_width_dots' => fake()->numberBetween(1, 1000),
            'width_dots' => fake()->numberBetween(1, 1000),
            'height_dots' => fake()->numberBetween(1, 1000),
            'data' => fake()->paragraphs(3, true),
            'content_hash' => fake()->sentence(),
            'byte_length' => fake()->numberBetween(1, 1000),
        ];
    }
}
