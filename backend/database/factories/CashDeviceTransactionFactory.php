<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CashDeviceTransaction;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Models\TillSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CashDeviceTransaction Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<CashDeviceTransaction>
 */
class CashDeviceTransactionFactory extends Factory
{
    protected $model = CashDeviceTransaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'peripheral_device_id' => PeripheralDevice::query()->inRandomOrder()->first()?->id ?? PeripheralDevice::factory()->create()->id,
            'glory_transaction_id' => fake()->sentence(),
            'outcome' => fake()->randomElement(['finish', 'cancel', 'abort', 'timeout', 'failure']),
            'requested_minor' => fake()->numberBetween(1, 1000),
            'deposited_minor' => fake()->numberBetween(1, 1000),
            'change_minor' => fake()->numberBetween(1, 1000),
            'dispensed_minor' => fake()->numberBetween(1, 1000),
            'order_payment_id' => OrderPayment::query()->inRandomOrder()->first()?->id,
            'customer_order_id' => CustomerOrder::query()->inRandomOrder()->first()?->id,
            'till_session_id' => TillSession::query()->inRandomOrder()->first()?->id,
            'error_title' => fake()->words(3, true),
            'machine_seq_no' => fake()->numberBetween(1, 1000),
            'started_at' => fake()->dateTime(),
            'finished_at' => fake()->dateTime(),
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
