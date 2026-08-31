<?php

namespace Database\Factories;

use App\Models\Denomination;
use App\Models\TillCashDenominationCount;
use App\Models\TillSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TillCashDenominationCount>
 */
class TillCashDenominationCountFactory extends Factory
{
    protected $model = TillCashDenominationCount::class;

    public function definition(): array
    {
        return [
            'count_phase' => 'opening',
            'quantity' => 10,
            'subtotal_amount' => 10000,
            'currency_code' => 'JPY',
            'denomination_value' => 1000,
            'denomination_kind' => 'note',
            'session_id' => TillSession::query()->inRandomOrder()->first()?->id ?? TillSession::factory()->create()->id,
            'denomination_id' => Denomination::query()->inRandomOrder()->first()?->id ?? Denomination::factory()->create()->id,
        ];
    }

    public function opening(): static
    {
        return $this->state(fn () => ['count_phase' => 'opening']);
    }

    public function closing(): static
    {
        return $this->state(fn () => ['count_phase' => 'closing']);
    }
}
