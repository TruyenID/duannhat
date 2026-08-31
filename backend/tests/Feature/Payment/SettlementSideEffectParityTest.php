<?php

use App\Events\OrderPaid;
use App\Mail\OrderPaidInvoiceMail;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\StockTransaction;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Services\Customer\OrderPaymentService;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Plan 047 Gate 4 (T4.8, non-money half) — settlement side-effect parity.
 *
 * The plan's core risk is that the second settlement engine (Stripe today)
 * diverges from the canonical path on the NON-money side effects that a paid
 * order must trigger exactly once: inventory stock-out, table release, table
 * -session close, close audit, paid-invoice mail, and the OrderPaid broadcast.
 *
 * The money parity lives in SettlementParityMatrixTest; this pins the full
 * side-effect signature and asserts it is IDENTICAL across every rail that
 * settles through OrderClosingService::close today (cash, card terminal, Stripe
 * webhook full payment after T4.6). When T4.5 routes synchronous Stripe confirm
 * through the orchestrator, add it to `settling_rails` — any missing or duplicated side effect surfaces here, not in a support
 * ticket about a table that never freed or stock that never decremented.
 */
beforeEach(function () {
    config([
        'services.stripe.key' => 'pk_test_dummy',
        'services.stripe.secret' => 'sk_test_dummy',
        'services.stripe.webhook_secret' => 'whsec_test_dummy',
        'services.stripe.currency' => 'jpy',
    ]);

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->manager, $this->orgId);
    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'inventory_mode' => 'track_stock',
    ]);

    $zone = Zone::factory()->for($this->shop, 'branch')->create(['organization_id' => $this->orgId]);
    $this->table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);

    // Oversell allowed so the stock-out needs no pre-seeded StockLevel.
    Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
        'allow_negative_sales' => true,
        'auto_approve_stock_out' => true,
    ]);

    $this->payments = app(OrderPaymentService::class);
});

/**
 * A dine-in order at checkout: occupies a table, owns an open table session,
 * carries two served track_stock items, and left a takeaway email so the
 * paid-invoice mail path fires.
 */
function sideEffectDineInOrder(): CustomerOrder
{
    $session = TableSession::create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->shop->id,
        'table_id' => test()->table->id,
        'status' => TableSession::STATUS_OPEN,
        'opened_at' => now(),
    ]);

    $order = CustomerOrder::create([
        'order_code' => 'ORD-SE-'.Str::random(5),
        'order_type' => 'dine_in',
        'status' => 'checkout',
        'opened_at' => now(),
        'subtotal' => 1000,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1000,
        'paid_amount' => 0,
        'total_tip' => 0,
        'created_by_id' => test()->manager->id,
        'customer_id' => test()->customer->id,
        'table_session_id' => $session->id,
        'customer_takeaway_email' => 'guest@example.com',
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    Table::where('id', test()->table->id)->update([
        'current_order_id' => $order->id,
        'status' => 'occupied',
    ]);

    foreach ([500, 500] as $price) {
        $order->items()->create([
            'product_sku_id' => test()->sku->id,
            'quantity' => 1,
            'unit_price' => $price,
            'original_unit_price' => $price,
            'subtotal' => $price,
            'status' => 'served',
            'served_at' => now(),
            'tax_rate' => 0,
        ]);
    }

    return $order->load('items');
}

dataset('settling_rails', [
    'cash' => ['cash'],
    'card terminal' => ['cardTerminal'],
]);

it('fires the full non-money settlement side-effect set identically across rails', function (string $state) {
    Mail::fake();
    Event::fake([OrderPaid::class]);

    $method = PaymentMethod::factory()->{$state}()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
    ]);
    $order = sideEffectDineInOrder();

    $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 1000,
        'tendered_amount' => 1000,
        'received_by_id' => $this->manager->id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $order->refresh();

    // Order closed.
    expect($order->status->value)->toBe('closed');

    // Inventory: one stock_out over the two served items, stamped on the order.
    expect($order->stock_out_transaction_id)->not->toBeNull();
    $tx = StockTransaction::where('reference_id', $order->id)->where('type', 'stock_out')->first();
    expect($tx)->not->toBeNull()
        ->and($tx->items()->count())->toBe(2);

    // Table released and the table session closed.
    $this->table->refresh();
    expect($this->table->status->value)->toBe('free')
        ->and($this->table->current_order_id)->toBeNull()
        ->and(TableSession::find($order->table_session_id)->status)->toBe(TableSession::STATUS_CLOSED);

    // Close audit written for this order.
    expect(AuditLog::where('auditable_id', $order->id)->where('action', 'closed')->exists())->toBeTrue();

    // Paid-invoice mail queued once, OrderPaid broadcast once.
    Mail::assertQueued(OrderPaidInvoiceMail::class, 1);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
})->with('settling_rails');

it('fires the full non-money settlement side-effect set for stripe webhook full payment', function () {
    Mail::fake();
    Event::fake([OrderPaid::class]);

    $order = sideEffectDineInOrder();
    $order->forceFill([
        'stripe_payment_intent_id' => 'pi_side_effect_full',
        'status' => 'open',
    ])->save();

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));
    $service->markOrderPaidFromIntent(PaymentIntent::constructFrom([
        'id' => 'pi_side_effect_full',
        'object' => 'payment_intent',
        'amount' => 1000,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'full',
            'order_currency' => 'JPY',
        ],
    ]));

    $order->refresh();

    expect($order->status->value)->toBe('closed');

    expect($order->stock_out_transaction_id)->not->toBeNull();
    $tx = StockTransaction::where('reference_id', $order->id)->where('type', 'stock_out')->first();
    expect($tx)->not->toBeNull()
        ->and($tx->items()->count())->toBe(2);

    $this->table->refresh();
    expect($this->table->status->value)->toBe('free')
        ->and($this->table->current_order_id)->toBeNull()
        ->and(TableSession::find($order->table_session_id)->status)->toBe(TableSession::STATUS_CLOSED);

    expect(AuditLog::where('auditable_id', $order->id)->where('action', 'closed')->exists())->toBeTrue();

    Mail::assertQueued(OrderPaidInvoiceMail::class, 1);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});
