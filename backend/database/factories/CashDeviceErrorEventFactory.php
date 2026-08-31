<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CashDeviceErrorEvent;
use App\Models\CashDeviceTransaction;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Models\TillSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CashDeviceErrorEvent Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<CashDeviceErrorEvent>
 */
class CashDeviceErrorEventFactory extends Factory
{
    protected $model = CashDeviceErrorEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'peripheral_device_id' => PeripheralDevice::query()->inRandomOrder()->first()?->id ?? PeripheralDevice::factory()->create()->id,
            'error_title' => fake()->words(3, true),
            'error_group' => fake()->words(3, true),
            'occurred_at' => fake()->dateTime(),
            'cleared_at' => fake()->dateTime(),
            'cash_device_transaction_id' => CashDeviceTransaction::query()->inRandomOrder()->first()?->id,
            'till_session_id' => TillSession::query()->inRandomOrder()->first()?->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
