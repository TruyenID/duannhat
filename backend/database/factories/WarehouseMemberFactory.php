<?php

namespace Database\Factories;

use App\Models\Warehouse;
use App\Models\WarehouseMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * WarehouseMember Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<WarehouseMember>
 */
class WarehouseMemberFactory extends Factory
{
    protected $model = WarehouseMember::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::query()->inRandomOrder()->first()?->id ?? Warehouse::factory()->create()->id,
            'user_id' => (string) Str::uuid(),
            'role' => fake()->randomElement(['manager', 'staff']),
        ];
    }
}
