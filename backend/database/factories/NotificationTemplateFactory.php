<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * NotificationTemplate Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id,
            'key' => Str::random(32),
            'content' => [],
            'default_channels' => [],
            'params_schema' => [],
            'is_system' => fake()->boolean(),
            'created_by_id' => User::query()->inRandomOrder()->first()?->id,
        ];
    }
}
