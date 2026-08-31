<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use Illuminate\Support\Str;

/*
 * Plan-044 R2 (endpoint D) — POST /workstation/payments/{payment}/attribution.
 *
 * Cloud accept for the workstation's gap-claim propagation: sets the payment's
 * till_session_id to a Cloud session id after a local claim. R6 — applied only
 * if the session belongs to the device branch; a foreign/unknown id is a no-op
 * (never nullifies, never 422); idempotent.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->till = Till::factory()->create([
        'till_code' => 'MAIN', 'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    $this->cash = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation', 'status' => 'active', 'device_token' => $this->wsToken,
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
    ]);
});

function gapPayment(object $ctx, ?string $tillSessionId = null, ?Branch $branch = null, ?string $orgId = null): OrderPayment
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-D'.random_int(1000, 9999),
        'order_type' => 'dine_in', 'status' => 'paying',
        'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0, 'opened_at' => now(),
        'branch_id' => ($branch ?? $ctx->branch)->id, 'brand_id' => $ctx->brand->id, 'organization_id' => $orgId ?? $ctx->orgId,
    ]);

    return OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id, 'payment_method_id' => $ctx->cash->id, 'amount' => 1000,
        'refund_of_id' => null, 'till_session_id' => $tillSessionId, 'received_by_id' => (string) Str::uuid(),
        'organization_id' => $orgId ?? $ctx->orgId, 'branch_id' => ($branch ?? $ctx->branch)->id, 'brand_id' => $ctx->brand->id,
    ]);
}

function attributionPost(object $ctx, string $paymentId, ?string $sessionId)
{
    return $ctx->withHeaders(['Authorization' => "Bearer {$ctx->wsToken}"])
        ->postJson("/api/v1/workstation/payments/{$paymentId}/attribution", ['till_session_id' => $sessionId]);
}

it('applies a same-branch session id to the payment (R6)', function () {
    $session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id, 'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    $payment = gapPayment($this);

    attributionPost($this, $payment->id, $session->id)->assertOk();

    expect($payment->fresh()->till_session_id)->toBe($session->id);
});

it('is a no-op (never 422, never nullifies) for a FOREIGN-branch session id', function () {
    // A session in another branch/org.
    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $foreignOrgId, 'console_organization_id' => $foreignOrgId]);
    $fBrand = Brand::factory()->create(['console_organization_id' => $foreignOrgId]);
    $fBranch = Branch::factory()->create(['console_organization_id' => $foreignOrgId, 'console_brand_id' => $fBrand->console_brand_id, 'is_active' => true]);
    $fTill = Till::factory()->create(['till_code' => 'MAIN', 'branch_id' => $fBranch->id, 'brand_id' => $fBrand->id, 'organization_id' => $foreignOrgId]);
    $foreignSession = TillSession::factory()->settled()->create([
        'till_id' => $fTill->id, 'branch_id' => $fBranch->id, 'brand_id' => $fBrand->id, 'organization_id' => $foreignOrgId,
    ]);

    $payment = gapPayment($this); // our branch, NULL

    attributionPost($this, $payment->id, $foreignSession->id)->assertOk();

    expect($payment->fresh()->till_session_id)->toBeNull(); // untouched, not nullified-from-something
});

it('is a no-op for an unknown/random session id (tolerant)', function () {
    $payment = gapPayment($this);

    attributionPost($this, $payment->id, (string) Str::uuid())->assertOk();

    expect($payment->fresh()->till_session_id)->toBeNull();
});

it('is idempotent — re-posting the same value changes nothing', function () {
    $session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id, 'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    $payment = gapPayment($this);

    attributionPost($this, $payment->id, $session->id)->assertOk();
    $after1 = $payment->fresh()->updated_at;
    attributionPost($this, $payment->id, $session->id)->assertOk();

    expect($payment->fresh()->till_session_id)->toBe($session->id)
        ->and($payment->fresh()->updated_at->equalTo($after1))->toBeTrue(); // no re-write
});

it('404s when the payment is unknown to the device branch', function () {
    attributionPost($this, (string) Str::uuid(), null)->assertNotFound();
});
