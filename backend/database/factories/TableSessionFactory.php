<?php

namespace Database\Factories;

use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * TableSession Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<TableSession>
 */
class TableSessionFactory extends Factory
{
    protected $model = TableSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'branch_id' => (string) Str::uuid(),
            'table_id' => Table::query()->inRandomOrder()->first()?->id ?? Table::factory()->create()->id,
            'status' => fake()->words(3, true),
            'opened_at' => fake()->dateTime(),
            'closed_at' => fake()->dateTime(),
        ];
    }
}
