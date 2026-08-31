<?php

namespace Database\Factories;

use App\Models\PaymentGatewayConnection;
use App\Models\SettlementReportBatch;
use App\Services\Payment\Settlement\Enums\SettlementReportBatchStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * SettlementReportBatch Factory (plan-050, #1155)
 *
 * @extends Factory<SettlementReportBatch>
 */
class SettlementReportBatchFactory extends Factory
{
    protected $model = SettlementReportBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => PaymentGatewayConnection::query()->inRandomOrder()->first()?->id
                ?? PaymentGatewayConnection::factory()->create()->id,
            'provider' => 'paypay',
            'cycle_label' => fake()->date('Y-m'),
            'file_hash' => hash('sha256', fake()->unique()->uuid()),
            'row_count' => 0,
            'matched_count' => 0,
            'orphan_count' => 0,
            'imported_by_id' => null,
            'imported_at' => now(),
            'status' => SettlementReportBatchStatus::Imported,
        ];
    }
}
