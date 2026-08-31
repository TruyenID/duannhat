<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Recipe Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    protected $model = Recipe::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'brand_id' => (string) Str::uuid(),
            // #1132 — UNIQUE (organization_id, sku): random words collide in a
            // full-suite run; unique() makes the flake structurally impossible.
            'sku' => fake()->unique()->words(3, true),
            'name' => fake()->sentence(3),
            'description' => fake()->paragraphs(3, true),
            'material_id' => Material::query()->inRandomOrder()->first()?->id,
            'output_quantity' => fake()->randomFloat(2, 1, 10000),
            'output_unit' => fake()->words(3, true),
            'ingredients' => [],
            'preparation_time' => fake()->numberBetween(1, 1000),
            'instructions' => fake()->paragraphs(3, true),
            'is_active' => fake()->boolean(),
        ];
    }
}
