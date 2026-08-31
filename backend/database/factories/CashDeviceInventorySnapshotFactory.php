<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CashDeviceInventorySnapshot;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Models\TillSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CashDeviceInventorySnapshot Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<CashDeviceInventorySnapshot>
 */
class CashDeviceInventorySnapshotFactory extends Factory
{
    protected $model = CashDeviceInventorySnapshot::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'peripheral_device_id' => PeripheralDevice::query()->inRandomOrder()->first()?->id ?? PeripheralDevice::factory()->create()->id,
            'till_session_id' => TillSession::query()->inRandomOrder()->first()?->id ?? TillSession::factory()->create()->id,
            'count_phase' => fake()->randomElement(['opening', 'closing']),
            'denominations' => [],
            'uncertain_denominations' => [],
            'bill_reject_count' => fake()->numberBetween(0, 100),
            'total_minor' => fake()->numberBetween(1, 1000),
            'machine_seq_no' => fake()->numberBetween(1, 1000),
            'captured_at' => fake()->dateTime(),
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
