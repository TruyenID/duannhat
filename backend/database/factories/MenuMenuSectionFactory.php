<?php

namespace Database\Factories;

use App\Models\MenuMenuSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MenuMenuSection Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<MenuMenuSection>
 */
class MenuMenuSectionFactory extends Factory
{
    protected $model = MenuMenuSection::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'display_order' => fake()->numberBetween(1, 100),
        ];
    }
}
