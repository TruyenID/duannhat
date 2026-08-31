<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Post Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'author_id' => User::query()->inRandomOrder()->first()?->id,
            'category_id' => PostCategory::query()->inRandomOrder()->first()?->id,
            'slug' => fake()->unique()->slug(2),
            'title' => fake()->sentence(3),
            'excerpt' => fake()->paragraphs(3, true),
            'content' => fake()->paragraphs(5, true),
            'cover_image_url' => fake()->imageUrl(),
            'status' => fake()->randomElement(['Draft', 'Published', 'Archived']),
            'published_at' => fake()->dateTime(),
            'is_pinned' => fake()->boolean(),
            'view_count' => fake()->sentence(),
        ];
    }
}
