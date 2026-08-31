<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ProductType Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ProductType>
 */
class ProductTypeFactory extends Factory
{
    protected $model = ProductType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::factory()->create()->id,
            'brand_id' => fn () => Brand::factory()->create()->id,
            'code' => \fake()->unique()->regexify('[A-Z0-9]{8}'),
            'name' => \fake()->sentence(3),
            'description' => \fake()->paragraphs(3, true),
            'product_form' => \fake()->randomElement(['physical', 'digital']),
            'has_recipe' => \fake()->boolean(),
            'is_inventory_tracked' => \fake()->boolean(),
            'icon' => \fake()->words(3, true),
            'is_active' => true,
        ];
    }
}
