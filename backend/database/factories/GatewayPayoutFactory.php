<?php

namespace Database\Factories;

use App\Models\GatewayPayout;
use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Settlement\Enums\GatewayPayoutStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * GatewayPayout Factory (plan-050, #1155)
 *
 * @extends Factory<GatewayPayout>
 */
class GatewayPayoutFactory extends Factory
{
    protected $model = GatewayPayout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $net = fake()->numberBetween(1_000, 500_000);

        return [
            'connection_id' => PaymentGatewayConnection::query()->inRandomOrder()->first()?->id
                ?? PaymentGatewayConnection::factory()->create()->id,
            'provider' => 'stripe',
            'external_payout_id' => 'po_'.Str::lower(Str::random(20)),
            'expected_arrival_date' => fake()->dateTimeBetween('now', '+7 days'),
            'paid_at' => null,
            'gross_minor' => $net,
            'fee_minor' => 0,
            'net_minor' => $net,
            'currency' => 'JPY',
            'status' => GatewayPayoutStatus::Pending,
            'reconciled_at' => null,
            'bank_ref' => null,
            'metadata' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => GatewayPayoutStatus::Paid,
            'paid_at' => fake()->dateTimeBetween('-7 days'),
        ]);
    }
}
