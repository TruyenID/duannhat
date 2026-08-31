<?php

namespace Database\Factories;

use App\Models\DisposalRecord;
use App\Models\StockTransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * DisposalRecord Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<DisposalRecord>
 */
class DisposalRecordFactory extends Factory
{
    protected $model = DisposalRecord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_transaction_item_id' => StockTransactionItem::query()->inRandomOrder()->first()?->id ?? StockTransactionItem::factory()->create()->id,
            'disposal_reason' => fake()->randomElement(['expired', 'overproduction', 'damaged', 'quality', 'contaminated', 'other']),
            'cost_at_disposal' => fake()->randomFloat(2, 1, 10000),
            'note' => fake()->paragraphs(3, true),
        ];
    }
}
