<?php

/**
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace Database\Factories;

use App\Models\Brand;
use App\Models\MenuSection;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuSection>
 */
class MenuSectionFactory extends Factory
{
    protected $model = MenuSection::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'organization_id' => Organization::factory(),
            'brand_id' => Brand::factory(),
        ];
    }
}
