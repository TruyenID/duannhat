<?php

/**
 * Plan-004 — true confirm()↔close() race (TESTS.md L138).
 *
 * The headline concurrency scenario is:
 *   "given a paying order, when two payments are confirmed concurrently
 *    (race), then only one triggers close and no double stock-out occurs
 *    (DB lock)"
 *
 * The existing OrderClosingConcurrencyTest proves close() idempotency by
 * calling `closingService->close()` TWICE directly — it never exercises the
 * real payment-confirmation entry point that production actually races on.
 * This test closes that gap: it drives two independent OrderPayments through
 * `OrderPaymentService::confirm()`, each of which re-sums the ledger and calls
 * close() when the order is paid-enough. The DB-level `lockForUpdate` inside
 * close() must serialise the two, so exactly ONE stock-out StockTransaction is
 * written and the SKU stock is decremented exactly once — never twice.
 *
 * Single-process caveat (same as CouponConcurrencyTest / OrderClosing
 * ConcurrencyTest): SQLite in-memory serialises writes, so we model the
 * interleave by confirming the two payments back-to-back. The invariant proven
 * is the one that matters — the close() lock collapses two paid-enough confirms
 * into a single stock-out.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\ProductSku;
use App\Models\Role;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'allow_negative_sales' => false,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->user->assignRole($role, $this->orgId);
    $this->actingAs($this->user);

    // Manual-confirm method so payments stay `pending` until we confirm() them.
    $this->method = PaymentMethod::factory()->create([
        'code' => 'card',
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => false,
        'requires_tendered' => false,
    ]);

    $this->service = app(OrderPaymentService::class);
});

/**
 * Build a paying order (total 5000) with one track_stock item (qty 2) and a
 * StockLevel starting at 50, plus $count pending OrderPayments of $amount each.
 *
 * @return array{0: CustomerOrder, 1: ProductSku, 2: array<int, OrderPayment>}
 */
function raceFixture(int $amount, int $count): array
{
    $sku = ProductSku::factory()->create([
        'inventory_mode' => 'track_stock',
        'recipe_id' => null,
    ]);

    StockLevel::create([
        'warehouse_id' => test()->warehouse->id,
        'product_sku_id' => $sku->id,
        'quantity' => 50,
        'unit' => 'pcs',
        'alert_enabled' => false,
    ]);

    $order = CustomerOrder::factory()->paying()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'created_by_id' => test()->user->id,
        'total_amount' => 5000,
        'paid_amount' => 0,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 2,
        'status' => 'served',
    ]);

    $payments = [];
    for ($i = 0; $i < $count; $i++) {
        $payments[] = OrderPayment::factory()->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => test()->method->id,
            'organization_id' => test()->orgId,
            'brand_id' => test()->brand->id,
            'branch_id' => test()->branch->id,
            'received_by_id' => test()->user->id,
            'amount' => $amount,
            'status' => PaymentStatusEnum::Pending->value,
            'paid_at' => null,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    return [$order, $sku, $payments];
}

function salesTxnCount(CustomerOrder $order): int
{
    return StockTransaction::where('reference_id', $order->id)
        ->where('sub_type', 'sales')
        ->count();
}

// =========================================================================
//  TESTS.md L138 — two paid-enough confirms collapse to a single stock-out
// =========================================================================

it('creates exactly one stock-out when two full-remaining payments both confirm (confirm↔close race)', function () {
    // Classic race (see SplitOverpaymentRaceTest header): two payments each for
    // the full ¥5,000 remaining were created before either confirmed. Both,
    // when confirmed, individually satisfy isPaidEnough and reach close().
    [$order, $sku, $payments] = raceFixture(amount: 5000, count: 2);

    // First confirm — normal close path: stock-out + order Closed.
    $this->service->confirm($payments[0]);

    expect(salesTxnCount($order))->toBe(1);
    expect($order->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);

    // Second confirm — the racing loser. isPaidEnough is still true, so it
    // re-enters close(); the lockForUpdate re-reads status=Closed and returns
    // early. NO second stock-out, stock NOT deducted a second time.
    $this->service->confirm($payments[1]);

    expect(salesTxnCount($order))->toBe(1);

    $level = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('product_sku_id', $sku->id)
        ->first();
    expect((float) $level->quantity)->toBe(48.0); // 50 − 2, never 50 − 4
});

it('closes on the settling slice of a split tender and stocks out exactly once', function () {
    // Legitimate split: ¥3,000 + ¥2,000 = ¥5,000. The first confirm leaves the
    // order paying (3000 < 5000, no close); the closing slice triggers the
    // single stock-out. Proves the confirm path does not stock-out early.
    [$order, $sku, $payments] = raceFixture(amount: 3000, count: 1);
    $second = OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->method->id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'received_by_id' => $this->user->id,
        'amount' => 2000,
        'status' => PaymentStatusEnum::Pending->value,
        'paid_at' => null,
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->service->confirm($payments[0]);
    expect(salesTxnCount($order))->toBe(0);
    expect($order->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Paying->value);
    expect((float) $order->fresh()->paid_amount)->toBe(3000.0);

    $this->service->confirm($second);
    expect(salesTxnCount($order))->toBe(1);
    expect($order->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);

    $level = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('product_sku_id', $sku->id)
        ->first();
    expect((float) $level->quantity)->toBe(48.0);
});
