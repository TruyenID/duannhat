<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\OrderCondition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function runCustomerOrderConditionBackfillMigration(): void
{
    $migration = require database_path('migrations/2000_01_02_999999_manual_migration_backfill_customer_order_condition_columns.php');
    $migration->up();
}

function withLegacyCustomerOrderMoneyColumns(callable $callback): void
{
    Schema::table('customer_orders', function (Blueprint $table): void {
        if (! Schema::hasColumn('customer_orders', 'discount_amount')) {
            $table->decimal('discount_amount', 15, 2)->default(0);
        }
        if (! Schema::hasColumn('customer_orders', 'service_charge')) {
            $table->decimal('service_charge', 15, 2)->default(0);
        }
        if (! Schema::hasColumn('customer_orders', 'tax_amount')) {
            $table->decimal('tax_amount', 15, 2)->default(0);
        }
    });

    try {
        $callback();
    } finally {
        Schema::table('customer_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('customer_orders', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
            if (Schema::hasColumn('customer_orders', 'service_charge')) {
                $table->dropColumn('service_charge');
            }
            if (Schema::hasColumn('customer_orders', 'tax_amount')) {
                $table->dropColumn('tax_amount');
            }
        });
    }
}

it('backfill migration copies legacy columns into order_conditions', function () {
    withLegacyCustomerOrderMoneyColumns(function (): void {
        $order = CustomerOrder::factory()->create();

        DB::table('customer_orders')->where('id', $order->id)->update([
            'discount_amount' => 200,
            'service_charge' => 50,
            'tax_amount' => 100,
        ]);

        OrderCondition::query()->where('conditionable_id', $order->id)->delete();

        runCustomerOrderConditionBackfillMigration();

        $rows = OrderCondition::query()
            ->where('conditionable_id', $order->id)
            ->get()
            ->keyBy('type');

        expect($rows)->toHaveKeys(['discount', 'service_charge', 'tax'])
            ->and((float) $rows['discount']->amount)->toBe(-200.0)
            ->and((float) $rows['service_charge']->amount)->toBe(50.0)
            ->and((float) $rows['tax']->amount)->toBe(100.0);
    });
});

it('backfill migration aborts when column disagrees with existing ledger', function () {
    withLegacyCustomerOrderMoneyColumns(function (): void {
        $order = CustomerOrder::factory()->create();

        DB::table('customer_orders')->where('id', $order->id)->update([
            'discount_amount' => 0,
            'service_charge' => 0,
            'tax_amount' => 100,
        ]);

        $order->conditions()->create([
            'type' => 'tax',
            'source' => 'tax_type',
            'label' => '10%',
            'amount' => 999,
            'currency_code' => 'JPY',
        ]);

        expect(fn () => runCustomerOrderConditionBackfillMigration())
            ->toThrow(RuntimeException::class);

        expect((float) OrderCondition::query()
            ->where('conditionable_id', $order->id)
            ->where('type', 'tax')
            ->value('amount'))->toBe(999.0);
    });
});
