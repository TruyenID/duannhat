<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ProductSku Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ProductSku>
 */
class ProductSkuFactory extends Factory
{
    protected $model = ProductSku::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Each SKU gets its own product so that the unique
            // (product_id, option_signature) constraint is never violated
            // when multiple SKUs are created without explicit option values.
            // Callers that want a shared product should pass `product_id` explicitly.
            //
            // #902 — the auto-created product is `active` (sellable) by default
            // so the many order tests that build a line from a bare
            // `ProductSku::factory()` keep passing the new sellability gate.
            // Tests that specifically need a non-sellable product either pass an
            // explicit `product_id` or use the `draftProduct()` state below.
            'product_id' => fn () => Product::factory()->active()->create()->id,
            'sku' => fake()->unique()->bothify('PV-####-??'),
            'name' => fake()->sentence(3),
            'option_value1_id' => null,
            'option_value2_id' => null,
            'option_value3_id' => null,
            // option_signature is computed by ProductSkuObserver::saving();
            // tests that need multiple SKUs per product must assign distinct
            // option values via ::withOptionValues().
            'recipe_id' => null,
            'recipe_multiplier' => 1.0,
            // issue #875 — selling_price is the menu price (source of truth).
            // cost_price defaults to 0 (auto-derived from recipe/material later);
            // keeping it 0 matches is_cost_override=false + cost_price_auto=0.
            'selling_price' => fake()->randomFloat(2, 100, 5000),
            'cost_price' => 0,
            'cost_price_auto' => 0,
            'is_cost_override' => false,
            'is_active' => true,
        ];
    }

    /**
     * State: attach a unique option value to each SKU so multiple SKUs can
     * coexist on the same product without violating the
     * `(product_id, option_signature)` unique index.
     *
     * Useful when a test passes `product_id => $shared->id` and creates more
     * than one SKU — without this, every SKU would compute the same `||`
     * signature (no options) and the second insert would conflict.
     */
    public function withSequencedOption(): static
    {
        return $this->state(fn () => [
            'option_value1_id' => ProductOptionValue::factory()->create()->id,
        ]);
    }

    /**
     * State: attach option values and compute signature.
     */
    public function withOptionValues(
        ?ProductOptionValue $val1 = null,
        ?ProductOptionValue $val2 = null,
        ?ProductOptionValue $val3 = null,
    ): static {
        return $this->state(fn () => [
            'option_value1_id' => $val1?->id,
            'option_value2_id' => $val2?->id,
            'option_value3_id' => $val3?->id,
            // option_signature recomputed by observer on save
        ]);
    }

    /**
     * State: with a recipe.
     */
    public function withRecipe(): static
    {
        return $this->state(fn () => [
            'recipe_id' => fn () => Recipe::query()->inRandomOrder()->first()?->id ?? Recipe::factory()->create()->id,
        ]);
    }

    /**
     * State: inactive SKU.
     */
    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    /**
     * State: a fully sellable SKU (active SKU + activated product). Explicit
     * alias of the default for readability in order tests that want to make the
     * intent clear. See {@see ProductSku::isSellable()} (#902).
     */
    public function sellable(): static
    {
        return $this->state(fn () => [
            'product_id' => fn () => Product::factory()->active()->create()->id,
            'is_active' => true,
        ]);
    }

    /**
     * State: attach the SKU to a `draft` (non-sellable) product. Use when a
     * test builds a bare `ProductSku::factory()` but needs the parent product
     * to stay in the pre-approval lifecycle (#902).
     */
    public function draftProduct(): static
    {
        return $this->state(fn () => [
            'product_id' => fn () => Product::factory()->create()->id,
        ]);
    }
}
