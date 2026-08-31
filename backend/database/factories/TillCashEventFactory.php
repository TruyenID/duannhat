<?php

namespace Database\Factories;

use App\Models\TillCashEvent;
use App\Models\TillSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * TillCashEvent Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<TillCashEvent>
 */
class TillCashEventFactory extends Factory
{
    protected $model = TillCashEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_type' => fake()->randomElement(['paid_in', 'paid_out', 'loan_from_safe', 'pickup_to_safe']),
            'amount' => fake()->randomFloat(2, 1, 10000),
            'currency_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'reason' => fake()->paragraphs(3, true),
            'reference_no' => fake()->sentence(),
            'occurred_at' => fake()->dateTime(),
            'performed_by_id' => (string) Str::uuid(),
            'session_id' => TillSession::query()->inRandomOrder()->first()?->id ?? TillSession::factory()->create()->id,
        ];
    }
}
