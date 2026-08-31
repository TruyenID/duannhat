<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Category Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

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
            // #1132 — categories carry a UNIQUE (organization_id, sku) index;
            // three random dictionary words collide across a full-suite run.
            // unique() makes the flake structurally impossible.
            'sku' => fake()->unique()->words(3, true),
            'name' => fake()->sentence(3),
            'slug' => fake()->unique()->slug(2),
            'description' => fake()->paragraphs(3, true),
            'image_url' => fake()->imageUrl(),
            'is_active' => fake()->boolean(),
            'parent_id' => fn () => Category::query()->inRandomOrder()->first()?->id,
        ];
    }
}
