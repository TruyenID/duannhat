<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Material Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    protected $model = Material::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'brand_id' => (string) Str::uuid(),
            // #1132 — UNIQUE (organization_id, sku): random words collide in a
            // full-suite run; unique() makes the flake structurally impossible.
            'sku' => fake()->unique()->words(3, true),
            'name' => fake()->sentence(3),
            'description' => fake()->paragraphs(3, true),
            'yield_quantity' => fake()->randomFloat(2, 1, 10000),
            'yield_unit' => fake()->words(3, true),
            'calculated_cost' => fake()->randomFloat(2, 1, 10000),
            'is_active' => fake()->boolean(),
            'output_sku_id' => ProductSku::query()->inRandomOrder()->first()?->id,
            'requires_temperature_check' => false,
            'temperature_min' => null,
            'temperature_max' => null,
        ];
    }

    /**
     * Material that requires HACCP temperature monitoring (e.g. frozen, chilled).
     */
    public function withTemperatureCheck(float $min = -18, float $max = 4): static
    {
        return $this->state(fn () => [
            'requires_temperature_check' => true,
            'temperature_min' => $min,
            'temperature_max' => $max,
        ]);
    }
}
