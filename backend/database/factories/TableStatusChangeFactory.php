<?php

namespace Database\Factories;

use App\Models\Table;
use App\Models\TableStatusChange;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * TableStatusChange Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<TableStatusChange>
 */
class TableStatusChangeFactory extends Factory
{
    protected $model = TableStatusChange::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_status' => 'free',
            'to_status' => 'occupied',
            'changed_by_id' => (string) Str::uuid(),
            'changed_at' => now(),
            'note' => null,
            'table_id' => Table::query()->inRandomOrder()->first()?->id ?? Table::factory()->create()->id,
        ];
    }
}
