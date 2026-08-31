<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Warehouse Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

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
            'code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'name' => fake()->sentence(3),
            'type' => 'branch',
            'address' => fake()->paragraphs(3, true),

            // Deterministic and usable. These were `fake()->boolean()` — a coin
            // flip on every flag that gates whether stock actually moves.
            //
            // `is_active` was the worst: OrderClosingService::getDefaultWarehouse()
            // only looks for an ACTIVE warehouse on the branch, so half the time a
            // test's warehouse was invisible and the service silently auto-created
            // a strict `DEFAULT` one (allow_negative_sales = false) instead. The
            // stock-out then failed on insufficient stock and no transaction was
            // written — surfacing as a baffling "expected a stock_out row, got
            // null" in a test that never mentions warehouses.
            //
            // A test that wants a flag OFF (pending approval, inactive warehouse)
            // must now say so explicitly. That reads as intent, and it cannot flake.
            'is_active' => true,
            'auto_approve_stock_in' => true,
            'auto_approve_stock_out' => true,
            'auto_approve_batch' => true,
            'auto_approve_disposal' => true,
            'disposal_approval_threshold' => 0,
        ];
    }
}
