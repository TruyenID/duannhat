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
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Str;

/*
 * Issue #523 — cross-session refund accounting.
 *
 * A cash refund physically removes money from the drawer of whatever shift is
 * OPEN AT REFUND TIME. Before the fix, refund rows had no till_session_id and
 * were excluded from reconcile everywhere, so:
 *   - the refund shift's expected_cash never dropped (drawer reported short);
 *   - flipping the original to `refunded` retroactively erased the sale from
 *     the already-settled SALE shift the moment it was re-reconciled.
 *
 * The fix: stamp refund rows to the refund-time shift and count the refund as
 * a drawer OUTFLOW there, while the original sale keeps counting against its
 * own SALE shift.
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

    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
    ]);
});

/**
 * Create a `closed` cash-paid order + its succeeded cash payment, stamped to
 * $saleSession. Returns the original payment.
 */
function makeCashSale(object $ctx, TillSession $saleSession, float $amount): OrderPayment
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-S'.random_int(1000, 9999),
        'order_type' => 'dine_in',
        'status' => 'closed',
        'subtotal' => $amount,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $amount,
        'paid_amount' => $amount,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
    ]);

    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $ctx->cashMethod->id,
        'amount' => $amount,
        'status' => 'succeeded',
        'refund_of_id' => null,
        'till_session_id' => $saleSession->id,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $ctx->orgId,
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
    ]);
}

it('lands a cross-session cash refund in the refund shift, not the sale shift (#523)', function () {
    // Shift A — where the sale happened, now settled.
    $shiftA = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opening_float_amount' => 10000,
    ]);

    // Shift B — open at refund time.
    $shiftB = TillSession::factory()->open()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opening_float_amount' => 10000,
    ]);
    $this->till->update(['current_session_id' => $shiftB->id]);

    $payment = makeCashSale($this, $shiftA, 3000);

    // Refund the cash payment while shift B is the open one.
    $refund = app(OrderPaymentService::class)->refund($payment, []);

    // (A) The refund row is stamped to the refund-time shift (B).
    expect($refund->till_session_id)->toBe($shiftB->id);
    expect((float) $refund->amount)->toBe(-3000.0);
    expect($payment->fresh()->status->value)->toBe('refunded');

    $svc = app(TillSessionService::class);

    // (B) The SALE shift keeps its +3000 cash sale even though the original is
    // now `refunded` — re-reconciling A must NOT shrink the settled Z-report.
    $reconA = $svc->reconcile($shiftA->fresh());
    expect($reconA['cash']['cash_sales'])->toBe(3000.0);
    expect($reconA['cash']['expected_cash'])->toBe(13000.0);

    // (C) The REFUND shift's drawer drops by the refunded cash.
    $reconB = $svc->reconcile($shiftB->fresh());
    expect($reconB['cash']['cash_sales'])->toBe(-3000.0);
    expect($reconB['cash']['expected_cash'])->toBe(7000.0);
});

it('nets a same-shift full cash refund back to the opening float (#523)', function () {
    $shift = TillSession::factory()->open()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opening_float_amount' => 10000,
    ]);
    $this->till->update(['current_session_id' => $shift->id]);

    $payment = makeCashSale($this, $shift, 3000);

    $refund = app(OrderPaymentService::class)->refund($payment, []);
    expect($refund->till_session_id)->toBe($shift->id);

    // +3000 sale, -3000 refund, both in this shift → nets to zero.
    $recon = app(TillSessionService::class)->reconcile($shift->fresh());
    expect($recon['cash']['cash_sales'])->toBe(0.0);
    expect($recon['cash']['expected_cash'])->toBe(10000.0);
});

it('nets a same-shift PARTIAL cash refund to the un-refunded remainder (#523)', function () {
    // Latent bug the fix also closes: before, a partial same-shift refund
    // dropped the WHOLE original from cash_sales (original flipped to
    // `refunded`, both rows excluded) — the drawer over-reported the shortfall.
    $shift = TillSession::factory()->open()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opening_float_amount' => 10000,
    ]);
    $this->till->update(['current_session_id' => $shift->id]);

    $payment = makeCashSale($this, $shift, 3000);

    app(OrderPaymentService::class)->refund($payment, ['amount' => 1000]);

    // +3000 sale, -1000 refund → 2000 net cash still in the drawer.
    $recon = app(TillSessionService::class)->reconcile($shift->fresh());
    expect($recon['cash']['cash_sales'])->toBe(2000.0);
    expect($recon['cash']['expected_cash'])->toBe(12000.0);
});
