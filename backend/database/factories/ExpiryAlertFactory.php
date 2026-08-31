<?php

namespace Database\Factories;

use App\Models\ExpiryAlert;
use App\Models\MaterialLot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * ExpiryAlert Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<ExpiryAlert>
 */
class ExpiryAlertFactory extends Factory
{
    protected $model = ExpiryAlert::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_lot_id' => MaterialLot::query()->inRandomOrder()->first()?->id ?? MaterialLot::factory()->create()->id,
            // #1132 — expiry_alerts carries a UNIQUE (material_lot_id, threshold_days) index, and
            // tests pin material_lot_id and let the factory draw the threshold, so two rows collided
            // roughly once every thousand full-suite runs. Same latent flake
            // that fired for menus; unique() makes it structurally impossible.
            'threshold_days' => fake()->unique()->numberBetween(1, 1_000_000),
            'fired_at' => fake()->dateTime(),
            'notification_id' => (string) Str::uuid(),
        ];
    }
}
