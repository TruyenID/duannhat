<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Table;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\Zone;
use Illuminate\Support\Str;

/**
 * P5 — merge-table / unmerge-table / payment.refund on the
 * workstation-flow controller. Same auth + branch-scope contract as the
 * P2/P3/P4 endpoints; idempotency is the focus here because each of
 * these ops mutates shared state (table_id, paid_amount).
 */
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
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    $this->wsDevice = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->zone = Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);
});

function p5_mkTable(string $code): Table
{
    return Table::factory()->create([
        'branch_id' => test()->branch->id,
        'zone_id' => test()->zone->id,
        'organization_id' => test()->orgId,
        'code' => $code,
        'status' => 'free',
    ]);
}

function p5_mkOrder(string $tableId): CustomerOrder
{
    $o = CustomerOrder::create([
        'order_code' => 'P5-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 1000,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1000,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'table_id' => $tableId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    // tables() is reverse HasMany on Table.current_order_id, so we point
    // the primary table back at the order.
    Table::where('id', $tableId)->update(['current_order_id' => $o->id]);

    return $o;
}

function p5_headers(): array
{
    return ['Authorization' => 'Bearer '.test()->wsToken];
}

it('merges a second table onto a dine-in order', function () {
    $t1 = p5_mkTable('A1');
    $t2 = p5_mkTable('A2');
    $order = p5_mkOrder($t1->id);

    $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$order->id}/merge-table", ['table_id' => $t2->id])
        ->assertOk();

    expect($order->tables()->count())->toBe(2);
});

it('is idempotent on merge replay', function () {
    $t1 = p5_mkTable('B1');
    $t2 = p5_mkTable('B2');
    $order = p5_mkOrder($t1->id);

    $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$order->id}/merge-table", ['table_id' => $t2->id])
        ->assertOk();
    $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$order->id}/merge-table", ['table_id' => $t2->id])
        ->assertOk();

    expect($order->tables()->count())->toBe(2); // not 3
});

it('unmerges a non-primary table', function () {
    $t1 = p5_mkTable('C1');
    $t2 = p5_mkTable('C2');
    $order = p5_mkOrder($t1->id);
    Table::where('id', $t2->id)->update(['current_order_id' => $order->id]);

    $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$order->id}/unmerge-table", ['table_id' => $t2->id])
        ->assertOk();

    expect($order->tables()->count())->toBe(1);
});

it('is idempotent on unmerge replay', function () {
    $t1 = p5_mkTable('D1');
    $t2 = p5_mkTable('D2');
    $order = p5_mkOrder($t1->id);
    Table::where('id', $t2->id)->update(['current_order_id' => $order->id]);

    $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$order->id}/unmerge-table", ['table_id' => $t2->id])
        ->assertOk();
    $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$order->id}/unmerge-table", ['table_id' => $t2->id])
        ->assertOk();

    expect($order->tables()->count())->toBe(1);
});

it('rejects merge from another branch', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $foreign = CustomerOrder::create([
        'order_code' => 'F-1',
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 100,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 100,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $otherBranch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $t = p5_mkTable('FX');

    $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$foreign->id}/merge-table", ['table_id' => $t->id])
        ->assertNotFound();
});

it('refunds a payment partially and updates order paid_amount', function () {
    $t = p5_mkTable('R1');
    $order = p5_mkOrder($t->id);
    $order->update(['paid_amount' => 1000, 'status' => 'closed']);

    $method = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $payment = OrderPayment::create([
        'payment_code' => 'PAY-'.Str::random(6),
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 1000,
        'tendered_amount' => 1000,
        'received_by_id' => $this->wsDevice->id,
        'status' => 'succeeded',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $refundID = (string) Str::uuid();
    $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$order->id}/payments/{$payment->id}/refund", [
            'refund_id' => $refundID,
            'amount' => 400,
            'note' => 'wrong order',
        ])
        ->assertOk()
        ->assertJsonPath('data.refunded_amount', 400);

    // Refund stored as a negative-amount OrderPayment row (Cloud model).
    expect((float) OrderPayment::where('refund_of_id', $payment->id)->sum('amount'))->toBe(-400.0);
});

it('is idempotent on refund replay', function () {
    $t = p5_mkTable('R2');
    $order = p5_mkOrder($t->id);
    $order->update(['paid_amount' => 1000, 'status' => 'closed']);

    $method = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $payment = OrderPayment::create([
        'payment_code' => 'PAY-'.Str::random(6),
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 1000,
        'tendered_amount' => 1000,
        'received_by_id' => $this->wsDevice->id,
        'status' => 'succeeded',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $refundID = (string) Str::uuid();
    $first = $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$order->id}/payments/{$payment->id}/refund", [
            'refund_id' => $refundID,
            'amount' => 300,
        ])
        ->assertOk()
        ->json('data.refunded_amount');

    $second = $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$order->id}/payments/{$payment->id}/refund", [
            'refund_id' => $refundID,
            'amount' => 300,
        ])
        ->assertOk()
        ->json('data.refunded_amount');

    expect($first)->toBe(300);
    expect($second)->toBe(300); // NOT 600
});

// #2657 — the guard added to the Cloud refund endpoint
// (Api/V1/Shop/OrderPaymentController::refund) refuses a workstation-origin
// payment while its shift is open. That is the whole point, but the two
// controllers SHARE OrderPaymentService::refund, so a guard placed one layer
// too deep would also kill this route — the only correct way to refund drawer
// money. If this test ever goes red, the change is worse than the bug it fixes.
it('#2657 sync-UP refund still succeeds for a workstation payment whose shift is OPEN', function () {
    $t = p5_mkTable('R5');
    $order = p5_mkOrder($t->id);
    $order->update(['paid_amount' => 1000, 'status' => 'closed']);

    $method = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $till = Till::factory()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $session = TillSession::factory()->create([
        'status' => 'open',
        'till_id' => $till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $till->update(['current_session_id' => $session->id]);

    // Exactly the row shape the Cloud endpoint now refuses.
    $payment = OrderPayment::create([
        'payment_code' => 'PAY-'.Str::random(6),
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 1000,
        'tendered_amount' => 1000,
        'received_by_id' => $this->wsDevice->id,
        'status' => 'succeeded',
        'channel' => 'workstation',
        'till_session_id' => $session->id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$order->id}/payments/{$payment->id}/refund", [
            'refund_id' => (string) Str::uuid(),
            'amount' => 400,
            'note' => 'refunded at the drawer',
        ])
        ->assertOk()
        ->assertJsonPath('data.refunded_amount', 400);

    expect((float) OrderPayment::where('refund_of_id', $payment->id)->sum('amount'))->toBe(-400.0);
    expect($payment->fresh()->status->value)->toBe('refunded');
});

it('rejects refund when payment belongs to a different order', function () {
    $t = p5_mkTable('R3');
    $order = p5_mkOrder($t->id);
    $other = p5_mkOrder(p5_mkTable('R4')->id);

    $method = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $payment = OrderPayment::create([
        'payment_code' => 'PAY-'.Str::random(6),
        'customer_order_id' => $other->id,
        'payment_method_id' => $method->id,
        'amount' => 500,
        'tendered_amount' => 500,
        'received_by_id' => $this->wsDevice->id,
        'status' => 'succeeded',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(p5_headers())
        ->postJson("/api/v1/workstation/orders/{$order->id}/payments/{$payment->id}/refund", [
            'refund_id' => (string) Str::uuid(),
            'amount' => 100,
        ])
        ->assertNotFound();
});
