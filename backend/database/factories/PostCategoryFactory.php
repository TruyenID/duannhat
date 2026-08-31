<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\PostCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PostCategory Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PostCategory>
 */
class PostCategoryFactory extends Factory
{
    protected $model = PostCategory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'name' => fake()->sentence(3),
            'slug' => fake()->unique()->slug(2),
            'description' => fake()->paragraphs(3, true),
            'image_url' => fake()->imageUrl(),
            'sort_order' => fake()->sentence(),
            'is_active' => fake()->boolean(),
            'parent_id' => PostCategory::query()->inRandomOrder()->first()?->id,
        ];
    }
}
