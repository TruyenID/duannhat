<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Device Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'type' => fake()->randomElement(['tms', 'pos', 'kds']),
            'status' => fake()->randomElement(['pending_activation', 'active', 'inactive', 'revoked']),
            'pairing_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'pairing_expires_at' => fake()->dateTime(),
            'device_token' => Str::random(32),
            'paired_at' => fake()->dateTime(),
            'last_seen_at' => fake()->dateTime(),
            'device_info' => [],
            'notes' => fake()->paragraphs(3, true),
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
        ];
    }
}
