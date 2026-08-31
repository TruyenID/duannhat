<?php

namespace Database\Factories;

use App\Models\MoneyReconciliationTask;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * MoneyReconciliationTask Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MoneyReconciliationTask>
 */
class MoneyReconciliationTaskFactory extends Factory
{
    protected $model = MoneyReconciliationTask::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_type' => fake()->words(3, true),
            'subject_type' => fake()->words(3, true),
            'subject_id' => (string) Str::uuid(),
            'branch_id' => (string) Str::uuid(),
            'organization_id' => (string) Str::uuid(),
            'payload' => [],
            'status' => fake()->words(3, true),
            'attempts' => fake()->numberBetween(1, 1000),
            'last_error' => fake()->sentence(),
            'resolution' => fake()->sentence(),
            'claimed_at' => fake()->dateTime(),
            'resolved_at' => fake()->dateTime(),
            'next_retry_at' => fake()->dateTime(),
        ];
    }
}
