<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * BrandOrderPolicy Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<BrandOrderPolicy>
 */
class BrandOrderPolicyFactory extends Factory
{
    protected $model = BrandOrderPolicy::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'default_prep_before_payment' => fake()->boolean(),
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id ?? Brand::factory()->create()->id,
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
        ];
    }
}
