<?php

namespace Database\Factories;

use App\Models\StockTransaction;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Omnify\Enums\StockTransferStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * StockTransfer Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<StockTransfer>
 */
class StockTransferFactory extends Factory
{
    protected $model = StockTransfer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => (string) Str::uuid(),
            'transfer_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'source_warehouse_id' => Warehouse::query()->inRandomOrder()->first()?->id ?? Warehouse::factory()->create()->id,
            'destination_warehouse_id' => Warehouse::query()->inRandomOrder()->first()?->id ?? Warehouse::factory()->create()->id,
            // `fake()->words(3, true)` produced a random sentence for a backed
            // enum column, so this factory could NEVER create a row — and it
            // took StockTransferItemFactory down with it, which builds its
            // parent through here. Draft is the state a transfer starts in.
            'status' => StockTransferStatusEnum::Draft->value,
            'stock_out_transaction_id' => StockTransaction::query()->inRandomOrder()->first()?->id,
            'stock_in_transaction_id' => StockTransaction::query()->inRandomOrder()->first()?->id,
            'note' => fake()->paragraphs(3, true),
            'created_by_id' => (string) Str::uuid(),
            'approved_by_id' => (string) Str::uuid(),
            'approved_at' => fake()->dateTime(),
            'received_by_id' => (string) Str::uuid(),
            'received_at' => fake()->dateTime(),
            'completed_at' => fake()->dateTime(),
        ];
    }
}
