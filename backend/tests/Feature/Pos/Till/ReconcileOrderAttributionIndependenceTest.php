<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Str;

/*
 * Plan-044 R2 — CASH-FLOW INDEPENDENCE PROOF (the owner's non-negotiable).
 *
 * The R2 pivot drops the order carry-over queue. This is only safe because the
 * drawer reconciliation (expected_cash / cash_variance / per-method sums) reads
 * ONLY order_payments.till_session_id — NEVER customer_orders.till_session_id.
 * This test pins that: reconcile() output is byte-identical whether the order
 * attribution column is set or forced NULL, with payments untouched. If a future
 * change makes reconcile read order attribution, this test fails loudly.
 */

it('reconcile() is byte-identical whether customer_orders.till_session_id is set or NULL', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);
    $till = Till::factory()->create([
        'till_code' => 'MAIN', 'branch_id' => $branch->id, 'brand_id' => $brand->id, 'organization_id' => $orgId,
    ]);
    $cash = PaymentMethod::factory()->cash()->create(['organization_id' => $orgId]);

    $session = TillSession::factory()->settled()->create([
        'till_id' => $till->id, 'branch_id' => $branch->id, 'brand_id' => $brand->id, 'organization_id' => $orgId,
        'opened_at' => now()->subHours(3), 'closed_at' => now()->subMinutes(5),
    ]);

    // An order + a succeeded cash payment attributed to the session (money in the
    // drawer), created inside the shift window. Order attribution set to the session.
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-IND'.random_int(1000, 9999),
        'order_type' => 'dine_in', 'status' => 'closed',
        'subtotal' => 3000, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 3000, 'paid_amount' => 3000, 'total_tip' => 0, 'opened_at' => now()->subHours(2),
        'till_session_id' => $session->id,
        'branch_id' => $branch->id, 'brand_id' => $brand->id, 'organization_id' => $orgId,
    ]);
    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id, 'payment_method_id' => $cash->id, 'amount' => 3000,
        'refund_of_id' => null, 'till_session_id' => $session->id, 'received_by_id' => (string) Str::uuid(),
        'organization_id' => $orgId, 'branch_id' => $branch->id, 'brand_id' => $brand->id,
        'created_at' => now()->subHours(1),
    ]);

    $service = app(TillSessionService::class);

    $withAttribution = $service->reconcile($session->fresh());

    // Null out the ORDER attribution only — payments untouched.
    CustomerOrder::where('branch_id', $branch->id)->update(['till_session_id' => null]);

    $withoutAttribution = $service->reconcile($session->fresh());

    expect($withoutAttribution)->toEqual($withAttribution);
    // And the money is real (not an empty-vs-empty coincidence).
    expect((float) ($withAttribution['cash']['expected_cash'] ?? 0))->toBeGreaterThan(0.0);
});
