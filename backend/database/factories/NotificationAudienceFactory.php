<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NotificationAudience Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<NotificationAudience>
 */
class NotificationAudienceFactory extends Factory
{
    protected $model = NotificationAudience::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id,
            'name' => fake()->sentence(3),
            'description' => fake()->paragraphs(3, true),
            'rule' => [],
            'is_system' => fake()->boolean(),
            'created_by_id' => User::query()->inRandomOrder()->first()?->id,
        ];
    }
}
