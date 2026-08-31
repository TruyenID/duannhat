<?php

namespace Database\Factories;

use App\Models\FloatingSection;
use App\Models\FloatingSectionProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * FloatingSectionProduct Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<FloatingSectionProduct>
 */
class FloatingSectionProductFactory extends Factory
{
    protected $model = FloatingSectionProduct::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'floating_section_id' => FloatingSection::query()->inRandomOrder()->first()?->id ?? FloatingSection::factory()->create()->id,
            'product_id' => Product::query()->inRandomOrder()->first()?->id ?? Product::factory()->create()->id,
            // Not random: a factory whose default is a coin-flip makes any test
            // that does not pin the flag pass or fail by luck. Rows land active
            // because that is what the fixtures under test almost always want.
            'is_active' => true,
            'display_order' => fake()->numberBetween(1, 100),
            // tax_type_id is deliberately absent — null means "inherit the
            // product's tier", which is the resolver's normal path. A random tax
            // type here would silently override that in every fixture.
        ];
    }
}
