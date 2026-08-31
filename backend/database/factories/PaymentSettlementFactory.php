<?php

namespace Database\Factories;

use App\Models\PaymentGatewayConnection;
use App\Models\PaymentSettlement;
use App\Services\Payment\Settlement\Enums\SettlementKind;
use App\Services\Payment\Settlement\Enums\SettlementSource;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * PaymentSettlement Factory (plan-050, #1155)
 *
 * @extends Factory<PaymentSettlement>
 */
class PaymentSettlementFactory extends Factory
{
    protected $model = PaymentSettlement::class;

    /**
     * Default: a JPY card payment row — gross 10 000, fee 360, fee_tax 0
     * (JP card fees are 非課税), net 9 640. The net invariant
     * `net = gross - fee - fee_tax` (S-15) holds by construction.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => PaymentGatewayConnection::query()->inRandomOrder()->first()?->id
                ?? PaymentGatewayConnection::factory()->create()->id,
            'provider' => 'stripe',
            'kind' => SettlementKind::Payment,
            'order_payment_id' => null,
            'payment_attempt_id' => null,
            'gross_minor' => 10_000,
            'fee_minor' => 360,
            'fee_tax_minor' => 0,
            'net_minor' => 9_640,
            'currency' => 'JPY',
            'source' => SettlementSource::Api,
            'external_ref' => 'txn_'.Str::lower(Str::random(20)),
            'report_batch_id' => null,
            'payout_id' => null,
            'provider_settled_at' => fake()->dateTimeBetween('-30 days'),
            'status' => SettlementStatus::PendingPayout,
            'metadata' => null,
        ];
    }

    public function refund(): static
    {
        // S-07: Stripe keeps the original fee — the refund row carries fee 0.
        return $this->state(fn (): array => [
            'kind' => SettlementKind::Refund,
            'gross_minor' => -10_000,
            'fee_minor' => 0,
            'fee_tax_minor' => 0,
            'net_minor' => -10_000,
        ]);
    }

    public function reconciled(): static
    {
        return $this->state(fn (): array => [
            'status' => SettlementStatus::Reconciled,
        ]);
    }

    public function orphan(): static
    {
        return $this->state(fn (): array => [
            'status' => SettlementStatus::Orphan,
        ]);
    }
}
