<?php

namespace Database\Factories;

use App\Models\Recall;
use App\Models\RecallAffectedOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * RecallAffectedOrder Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<RecallAffectedOrder>
 */
class RecallAffectedOrderFactory extends Factory
{
    protected $model = RecallAffectedOrder::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recall_id' => Recall::query()->inRandomOrder()->first()?->id ?? Recall::factory()->create()->id,
            'customer_order_id' => (string) Str::uuid(),
            'notification_channel' => fake()->words(3, true),
            'notified_at' => fake()->dateTime(),
            'notification_id' => (string) Str::uuid(),
        ];
    }
}
