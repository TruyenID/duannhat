<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\RoleUserPivot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * RoleUserPivot Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<RoleUserPivot>
 */
class RoleUserPivotFactory extends Factory
{
    protected $model = RoleUserPivot::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => Role::query()->inRandomOrder()->first()?->id ?? Role::factory()->create()->id,
            'user_id' => User::query()->inRandomOrder()->first()?->id ?? User::factory()->create()->id,
            'organization_id' => (string) Str::uuid(),
            'branch_id' => (string) Str::uuid(),
        ];
    }
}
