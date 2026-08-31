<?php

namespace Database\Factories;

use App\Models\TaxType;
use App\Models\TaxTypeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * TaxTypeRate Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<TaxTypeRate>
 */
class TaxTypeRateFactory extends Factory
{
    protected $model = TaxTypeRate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rate' => fake()->randomFloat(2, 1, 10000),
            'effective_from' => fake()->date(),
            'effective_to' => fake()->date(),
            'tax_type_id' => TaxType::query()->inRandomOrder()->first()?->id ?? TaxType::factory()->create()->id,
        ];
    }
}
