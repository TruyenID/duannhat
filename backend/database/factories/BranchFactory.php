<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'console_branch_id' => (string) Str::uuid(),
            'console_organization_id' => (string) Str::uuid(),
            'console_brand_id' => null,
            'code' => null,
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->company().' Shop',
            'is_headquarters' => false,
            'is_active' => true,
            'timezone' => 'Asia/Tokyo',
            'currency' => 'JPY',
            'locale' => 'ja',
        ];
    }
}
