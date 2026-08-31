<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\NotificationChannelRoute;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NotificationChannelRoute Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<NotificationChannelRoute>
 */
class NotificationChannelRouteFactory extends Factory
{
    protected $model = NotificationChannelRoute::class;

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
            'type' => fake()->sentence(),
            'channels' => [],
            'priority_overrides' => [],
        ];
    }
}
