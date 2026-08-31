<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Table Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Table>
 */
class TableFactory extends Factory
{
    protected $model = Table::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'T-'.fake()->unique()->numberBetween(1, 9999),
            'name' => null,
            'seat_count' => fake()->numberBetween(2, 8),
            'status' => 'free',
            'qr_token' => Str::random(32),
            'is_active' => true,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'zone_id' => Zone::query()->inRandomOrder()->first()?->id ?? Zone::factory()->create()->id,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
