<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Models\NotificationSchedule;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * NotificationSchedule Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<NotificationSchedule>
 */
class NotificationScheduleFactory extends Factory
{
    protected $model = NotificationSchedule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'template_key' => Str::random(32),
            'audience_id' => NotificationAudience::query()->inRandomOrder()->first()?->id ?? NotificationAudience::factory()->create()->id,
            'channels' => [],
            'priority' => fake()->words(3, true),
            'params' => [],
            'rrule' => fake()->sentence(),
            'timezone' => fake()->sentence(),
            'starts_at' => fake()->dateTime(),
            'ends_at' => fake()->dateTime(),
            'occurrences_remaining' => fake()->sentence(),
            'next_occurrence_at' => fake()->dateTime(),
            'last_occurrence_at' => fake()->dateTime(),
            'status' => fake()->words(3, true),
            'created_by_id' => User::query()->inRandomOrder()->first()?->id,
        ];
    }
}
