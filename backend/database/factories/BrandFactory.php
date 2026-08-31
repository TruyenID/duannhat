<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Brand Factory
 *
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'console_brand_id' => (string) Str::uuid(),
            'console_organization_id' => (string) Str::uuid(),
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'logo_url' => fake()->imageUrl(),
            'is_active' => true,
        ];
    }
}
