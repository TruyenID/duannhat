<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Till;
use App\Models\TillSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TillSession>
 */
class TillSessionFactory extends Factory
{
    protected $model = TillSession::class;

    public function definition(): array
    {
        return [
            'session_code' => 'SHIFT-'.now()->format('Ymd').'-'.fake()->unique()->numberBetween(100, 999),
            'status' => 'open',
            'business_date' => now()->toDateString(),
            'default_currency_code' => 'JPY',
            'opening_float_amount' => 100000,
            'expected_cash_amount' => null,
            'counted_cash_amount' => null,
            'cash_variance_amount' => null,
            'opening_note' => null,
            'closing_note' => null,
            'opened_by_id' => (string) Str::uuid(),
            'closed_by_id' => null,
            'opener_name' => null,
            'opened_at' => now(),
            'closed_at' => null,
            'abandoned_at' => null,
            'abandon_reason' => null,
            'till_id' => Till::query()->inRandomOrder()->first()?->id ?? Till::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => 'open']);
    }

    public function closing(): static
    {
        return $this->state(fn () => ['status' => 'closing']);
    }

    public function settled(): static
    {
        return $this->state(fn () => [
            'status' => 'settled',
            'closed_at' => now(),
            'closed_by_id' => (string) Str::uuid(),
            'expected_cash_amount' => 100000,
            'counted_cash_amount' => 100000,
            'cash_variance_amount' => 0,
        ]);
    }

    public function abandoned(): static
    {
        return $this->state(fn () => [
            'status' => 'abandoned',
            'abandoned_at' => now(),
            'abandon_reason' => 'Test abandon',
        ]);
    }
}
