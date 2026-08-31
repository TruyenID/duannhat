<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Services\PeripheralDevice\PeripheralDeviceService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Peripheral Device Factory
 */
class PeripheralDeviceFactory extends Factory
{
    protected $model = PeripheralDevice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            // plan-052 T1.5 — reads the registry rather than repeating it, so a
            // type retired from the service can never come back through a test.
            'type' => fake()->randomElement(PeripheralDeviceService::ALLOWED_TYPES),
            'is_active' => true,
            'metadata' => [],
            'secret' => fake()->optional(0.35)->word(),
            'registered_by_device_id' => fake()->optional(0.45)->uuid(),
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
        ];
    }

    /**
     * plan-052 P-18 — a legacy row from before printers left this registry.
     * Exists ONLY so the dedup guard and the purge command can be tested
     * against the shape of data that production may still be carrying.
     */
    public function retiredPrinterType(string $type = 'receipt_printer'): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }
}
