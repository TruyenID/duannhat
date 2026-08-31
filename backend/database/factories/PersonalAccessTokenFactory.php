<?php

namespace Database\Factories;

use App\Models\PersonalAccessToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * PersonalAccessToken Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PersonalAccessToken>
 */
class PersonalAccessTokenFactory extends Factory
{
    protected $model = PersonalAccessToken::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tokenable_type' => Str::random(32),
            'tokenable_id' => Str::random(32),
            'name' => fake()->paragraphs(3, true),
            'token' => Str::random(32),
            'abilities' => fake()->paragraphs(3, true),
            'last_used_at' => fake()->dateTime(),
            'expires_at' => fake()->dateTime(),
        ];
    }
}
