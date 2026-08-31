<?php

namespace Database\Factories;

use App\Models\File;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * File Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    protected $model = File::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'collection' => 'default',
            'disk' => 'local',
            'path' => 'uploads/temp/'.fake()->uuid().'.jpg',
            'original_name' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(1024, 5242880),
            'status' => FileStatusEnum::Temporary,
            'expires_at' => now()->addHours(12),
            'sort_order' => 0,
        ];
    }

    /**
     * File is permanent (attached to a model).
     */
    public function permanent(): static
    {
        return $this->state([
            'status' => FileStatusEnum::Permanent,
            'expires_at' => null,
        ]);
    }

    /**
     * File is temporary and expired.
     */
    public function expired(): static
    {
        return $this->state([
            'status' => FileStatusEnum::Temporary,
            'expires_at' => now()->subHour(),
        ]);
    }
}
