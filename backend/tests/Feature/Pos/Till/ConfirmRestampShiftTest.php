<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Support\Str;

/*
 * Issue #552 — confirming a non-cash payment after its shift has settled must
 * NOT keep the payment stamped to the (already reconciled) shift. reconcile()
 * computes revenue live, so an old settled shift would silently gain revenue
 * after the fact. confirm() re-stamps the payment to the currently-open shift.
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

    $this->till = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->cardMethod = PaymentMethod::factory()->card()->create([
        'organization_id' => $this->orgId,
    ]);
});

it('re-stamps a payment confirmed after its shift settled to the open shift (#552)', function () {
    // Shift A — already settled.
    $shiftA = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    // Shift B — currently open.
    $shiftB = TillSession::factory()->open()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $this->till->update(['current_session_id' => $shiftB->id]);

    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-S'.random_int(1000, 9999),
        'order_type' => 'dine_in',
        'status' => 'paying',
        'subtotal' => 3000,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 3000,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    // Pending card payment created during shift A (partial, so confirm won't
    // try to close the order — keeps the test off the inventory path).
    $payment = OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cardMethod->id,
        'amount' => 1000,
        'status' => 'pending',
        'till_session_id' => $shiftA->id,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    app(OrderPaymentService::class)->confirm($payment);

    $fresh = $payment->fresh();
    expect($fresh->status->value)->toBe('succeeded');
    // The revenue must belong to the OPEN shift B, not the settled shift A.
    expect($fresh->till_session_id)->toBe($shiftB->id);
    expect($fresh->till_session_id)->not->toBe($shiftA->id);
});

it('detaches a payment confirmed after its shift settled when NO shift is open (#552)', function () {
    // Shift A — already settled. No shift is currently open on the till
    // (current_session_id stays null), so confirm() has nowhere to re-stamp.
    $shiftA = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-D'.random_int(1000, 9999),
        'order_type' => 'dine_in',
        'status' => 'paying',
        'subtotal' => 3000,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 3000,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    // Pending card payment created during shift A (partial, so confirm won't
    // try to close the order).
    $payment = OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cardMethod->id,
        'amount' => 1000,
        'status' => 'pending',
        'till_session_id' => $shiftA->id,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    app(OrderPaymentService::class)->confirm($payment);

    $fresh = $payment->fresh();
    expect($fresh->status->value)->toBe('succeeded');
    // No open shift + original shift settled → DETACH. Leaving it on shift A is
    // the #552 leak (reconcile counts it live → A's settled gross retro-grows).
    expect($fresh->till_session_id)->toBeNull();
});
