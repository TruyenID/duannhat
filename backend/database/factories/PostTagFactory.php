<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\PostTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PostTag Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PostTag>
 */
class PostTagFactory extends Factory
{
    protected $model = PostTag::class;

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
            'color' => fake()->hexColor(),
        ];
    }
}
