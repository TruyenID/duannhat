<?php

namespace Database\Factories;

use App\Models\TillSession;
use App\Models\TillSettlementTenderDetail;
use App\Models\TillTenderType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * TillSettlementTenderDetail Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<TillSettlementTenderDetail>
 */
class TillSettlementTenderDetailFactory extends Factory
{
    protected $model = TillSettlementTenderDetail::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tender_key' => Str::random(32),
            'category' => fake()->randomElement(['cash', 'card', 'qr', 'emoney']),
            'currency_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'expected_amount' => fake()->randomFloat(2, 1, 10000),
            'declared_gross_amount' => fake()->randomFloat(2, 1, 10000),
            'declared_cancel_amount' => fake()->randomFloat(2, 1, 10000),
            'declared_amount' => fake()->randomFloat(2, 1, 10000),
            'terminal_batch_total' => fake()->randomFloat(2, 1, 10000),
            'variance_amount' => fake()->randomFloat(2, 1, 10000),
            'variance_reason' => fake()->paragraphs(3, true),
            'session_id' => TillSession::query()->inRandomOrder()->first()?->id ?? TillSession::factory()->create()->id,
            'tender_type_id' => TillTenderType::query()->inRandomOrder()->first()?->id ?? TillTenderType::factory()->create()->id,
        ];
    }
}
