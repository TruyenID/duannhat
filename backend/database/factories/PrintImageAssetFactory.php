<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PrintImageAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PrintImageAsset Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PrintImageAsset>
 */
class PrintImageAssetFactory extends Factory
{
    protected $model = PrintImageAsset::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id,
            'brand_id' => Brand::query()->inRandomOrder()->first()?->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id,
            'source' => fake()->words(3, true),
            'scope' => fake()->words(3, true),
            'version' => fake()->numberBetween(1, 1000),
            'status' => fake()->words(3, true),
            'original_path' => 'uploads/'.fake()->uuid().'.jpg',
            'original_mime' => fake()->sentence(),
            'original_filename' => 'uploads/'.fake()->uuid().'.jpg',
            'original_bytes' => fake()->numberBetween(1, 1000),
            'original_hash' => fake()->sentence(),
            'effective_from' => fake()->dateTime(),
            'notes' => fake()->sentence(),
            'created_by_id' => User::query()->inRandomOrder()->first()?->id,
            'published_by_id' => User::query()->inRandomOrder()->first()?->id,
            'published_at' => fake()->dateTime(),
        ];
    }
}
