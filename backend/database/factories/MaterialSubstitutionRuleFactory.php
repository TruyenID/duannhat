<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialSubstitutionRule;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MaterialSubstitutionRule Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MaterialSubstitutionRule>
 */
class MaterialSubstitutionRuleFactory extends Factory
{
    protected $model = MaterialSubstitutionRule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'primary_material_id' => Material::query()->inRandomOrder()->first()?->id ?? Material::factory()->create()->id,
            'substitute_material_id' => Material::query()->inRandomOrder()->first()?->id ?? Material::factory()->create()->id,
            'conversion_factor' => fake()->randomFloat(4, 0.5, 2.0),
            'priority' => fake()->numberBetween(0, 10),
            'is_active' => fake()->boolean(),
            'auto_substitute' => false,
            'valid_from' => null,
            'valid_until' => null,
            'notes' => fake()->paragraphs(3, true),
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
        ];
    }
}
