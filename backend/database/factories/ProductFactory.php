<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductType;
use App\Omnify\Enums\ProductStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Product Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::factory()->create()->id,
            'brand_id' => fn () => Brand::factory()->create()->id,
            'product_type_id' => fn () => ProductType::query()->inRandomOrder()->first()?->id ?? ProductType::factory()->create()->id,
            'name' => fake()->sentence(3),
            'slug' => fn () => Str::slug(fake()->unique()->words(3, true)),
            'description' => fake()->paragraphs(3, true),
            'status' => 'draft',
            'is_hidden' => false,
        ];
    }

    /**
     * State: an activated (sellable) product. The default status is `draft`
     * so approval-workflow tests keep working; order/menu tests that need a
     * SKU the #902 sellability gate accepts should use this state (or
     * {@see ProductSkuFactory::sellable()}).
     */
    public function active(): static
    {
        return $this->state(fn () => ['status' => ProductStatusEnum::Active->value]);
    }

    /**
     * State: scope the product to a specific brand and its parent organization.
     */
    public function forBrand(Brand $brand): static
    {
        return $this->state(fn () => [
            'brand_id' => $brand->id,
            'organization_id' => $brand->organization_id ?? $brand->console_organization_id,
        ]);
    }

    /**
     * State: after creation, attach `$count` ProductOption rows (positions 1..N)
     * each with `$valuesPerOption` ProductOptionValue rows. Useful for setting
     * up products that already have a Cartesian space ready for SKU
     * generation.
     */
    public function withOptions(int $count = 2, int $valuesPerOption = 3): static
    {
        return $this->afterCreating(function (Product $product) use ($count, $valuesPerOption) {
            for ($pos = 1; $pos <= $count; $pos++) {
                $option = ProductOption::factory()
                    ->state([
                        'product_id' => $product->id,
                        'position' => $pos,
                        'key' => 'opt'.$pos,
                        'name' => 'Option '.$pos,
                    ])
                    ->create();

                for ($v = 1; $v <= $valuesPerOption; $v++) {
                    ProductOptionValue::factory()
                        ->state([
                            'option_id' => $option->id,
                            'value' => 'v'.$pos.'-'.$v,
                            'label' => 'Value '.$pos.'.'.$v,
                            'position' => $v,
                        ])
                        ->create();
                }
            }
        });
    }
}
