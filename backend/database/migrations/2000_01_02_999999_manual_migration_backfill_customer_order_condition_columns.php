<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * #2041 — one-time copy of legacy customer_orders money columns into
 * order_conditions before Omnify drops the columns.
 *
 * Fail-closed: if a column value disagrees with an existing ledger sum,
 * migration aborts rather than silently losing history.
 */
return new class extends Migration
{
    private const TOLERANCE = 0.01;

    /** @var array<string, array{column: string, source: string, label: string, signed: bool}> */
    private const TYPES = [
        'discount' => [
            'column' => 'discount_amount',
            'source' => 'manual',
            'label' => 'Discount',
            'signed' => true,
        ],
        'service_charge' => [
            'column' => 'service_charge',
            'source' => 'service_charge',
            'label' => 'Service charge',
            'signed' => false,
        ],
        'tax' => [
            'column' => 'tax_amount',
            'source' => 'tax_type',
            'label' => 'Tax',
            'signed' => false,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasColumns('customer_orders', ['discount_amount', 'service_charge', 'tax_amount'])) {
            return;
        }

        $morphClass = (new CustomerOrder)->getMorphClass();
        $now = now();

        DB::table('customer_orders')
            ->select(['id', 'branch_id', 'discount_amount', 'service_charge', 'tax_amount'])
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($morphClass, $now): void {
                foreach ($orders as $order) {
                    $currency = (string) (DB::table('shop_order_settings')
                        ->where('branch_id', $order->branch_id)
                        ->value('currency_code') ?? 'JPY');

                    foreach (self::TYPES as $type => $spec) {
                        $columnValue = (float) $order->{$spec['column']};
                        if (abs($columnValue) < 0.000001) {
                            continue;
                        }

                        $expected = $spec['signed']
                            ? -abs($columnValue)
                            : $columnValue;

                        $ledgerSum = (float) DB::table('order_conditions')
                            ->where('conditionable_type', $morphClass)
                            ->where('conditionable_id', $order->id)
                            ->where('type', $type)
                            ->sum('amount');

                        if (abs($ledgerSum) < 0.000001) {
                            DB::table('order_conditions')->insert([
                                'id' => (string) Str::uuid(),
                                'conditionable_type' => $morphClass,
                                'conditionable_id' => $order->id,
                                'type' => $type,
                                'source' => $spec['source'],
                                'label' => $spec['label'],
                                'rate' => null,
                                'amount' => $expected,
                                'taxable_base' => null,
                                'currency_code' => $currency,
                                'meta' => null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);

                            continue;
                        }

                        if (abs($ledgerSum - $expected) > self::TOLERANCE) {
                            throw new RuntimeException(sprintf(
                                'customer_orders.%s mismatch for order %s: column=%.2f ledger=%.2f',
                                $spec['column'],
                                $order->id,
                                $columnValue,
                                $ledgerSum,
                            ));
                        }
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        // Data migration — no reverse.
    }
};
