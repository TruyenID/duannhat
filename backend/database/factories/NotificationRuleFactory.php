<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\NotificationRule;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NotificationRule Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<NotificationRule>
 */
class NotificationRuleFactory extends Factory
{
    protected $model = NotificationRule::class;

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
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id,
            'name' => fake()->sentence(3),
            'description' => fake()->paragraphs(3, true),
            'trigger_event' => fake()->sentence(),
            'trigger_model_type' => fake()->sentence(),
            'conditions' => [],
            'action' => [],
            'cooldown_minutes' => fake()->numberBetween(1, 1000),
            'is_active' => fake()->boolean(),
            'last_fired_at' => fake()->dateTime(),
            'fire_count' => fake()->numberBetween(0, 100),
            'created_by_id' => User::query()->inRandomOrder()->first()?->id,
        ];
    }
}
