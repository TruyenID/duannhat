<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MaterialUnit Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MaterialUnit>
 */
class MaterialUnitFactory extends Factory
{
    protected $model = MaterialUnit::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_id' => Material::query()->inRandomOrder()->first()?->id ?? Material::factory()->create()->id,
            'unit' => fake()->words(3, true),
            'ratio' => fake()->randomFloat(2, 1, 10000),
            'is_base' => fake()->boolean(),
        ];
    }
}
