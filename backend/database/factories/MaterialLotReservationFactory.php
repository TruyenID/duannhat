<?php

namespace Database\Factories;

use App\Models\MaterialLot;
use App\Models\MaterialLotReservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MaterialLotReservation>
 */
class MaterialLotReservationFactory extends Factory
{
    protected $model = MaterialLotReservation::class;

    public function definition(): array
    {
        return [
            'material_lot_id' => MaterialLot::factory(),
            'qty_reserved' => fake()->randomFloat(4, 1, 100),
            'reserved_by_id' => User::factory(),
            'expected_consume_at' => now()->addDays(3),
            'material_batch_id' => null,
            'status' => 'active',
            'reason' => fake()->sentence(),
            'organization_id' => (string) Str::uuid(),
        ];
    }

    public function consumed(): static
    {
        return $this->state(fn () => ['status' => 'consumed']);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'expected_consume_at' => now()->subDays(10),
        ]);
    }
}
