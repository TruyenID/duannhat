<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceSigningKey;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * DeviceSigningKey Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<DeviceSigningKey>
 */
class DeviceSigningKeyFactory extends Factory
{
    protected $model = DeviceSigningKey::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_key' => Str::random(32),
            'issued_at' => fake()->dateTime(),
            'expires_at' => fake()->dateTime(),
            'revoked_at' => fake()->dateTime(),
            'revoked_reason' => fake()->sentence(),
            'device_id' => Device::query()->inRandomOrder()->first()?->id ?? Device::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
