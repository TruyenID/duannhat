<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'console_organization_id' => (string) Str::uuid(),
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'is_active' => true,
            'settings' => null,
        ];
    }
}
