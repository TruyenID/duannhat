<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use Illuminate\Support\Str;

/**
 * Workstation-scoped payment endpoints (PR Q1 — Option B).
 *
 * These endpoints mirror /api/v1/kiosk/payments but live behind
 * device.auth:workstation. The workstation forwards each locally-recorded
 * payment here under its OWN device token, regardless of whether the
 * originating terminal was a kiosk (kiosk-typed device) or a POS web
 * client (SSO user, no device token of its own). Single endpoint,
 * single auth scope, single received_by_id audit trail.
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

    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);
});

function makeWsPaymentOrder(int $total): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-WS-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => $total,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'checkout_at' => now(),
        'customer_id' => test()->customer->id,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

it('creates a payment from a workstation sync UP', function () {
    $order = makeWsPaymentOrder(1500);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'payment_code', 'status', 'amount']]);

    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1);
    $payment = OrderPayment::where('customer_order_id', $order->id)->first();
    expect($payment->received_by_id)->toBe($this->wsDevice->id);
    expect((float) $payment->amount)->toBe(1500.0);
});

it('#859 — pays a Confirmed kiosk order re-homed through the workstation route (auto-promote to checkout)', function () {
    // Kiosk takeaway/counter-pay orders are created at status=confirmed and stay
    // there until payment. When a kiosk payment is re-homed via the workstation
    // reconciler (dead kiosk token), the order is still Confirmed. Before this fix
    // the workstation route only promoted Open/Dining, so the payment 409'd
    // ("must be in 'checkout' or 'paying'") and dead-lettered every time.
    $order = makeWsPaymentOrder(1500);
    $order->update(['status' => 'confirmed', 'checkout_at' => null]);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
    ])->assertCreated();

    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1);
    // Order was promoted off `confirmed` (past the OrderPaymentService 409 guard);
    // the full cash payment then auto-confirms and closes it out.
    expect($order->fresh()->status->value)->not->toBe('confirmed');
});

it('#817 B4 — forwards a cash tip without tripping the requires_tendered guard', function () {
    $order = makeWsPaymentOrder(1500);

    // Pre-fix: the controller auto-tendered only `amount` (1500) while cash is
    // requires_tendered, so a tip made tendered < amount + tip → the create
    // guard threw InvalidArgumentException → 500 and the sync item dead-lettered.
    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
        'tip_amount' => 200,
    ])->assertCreated();

    $payment = OrderPayment::where('customer_order_id', $order->id)->firstOrFail();
    expect((float) $payment->tip_amount)->toBe(200.0);       // reconcile() adds this back into expected_cash
    expect((float) $payment->tendered_amount)->toBe(1700.0); // amount + tip
    expect((float) $payment->change_amount)->toBe(0.0);
});

it('keeps the cash the cashier actually counted instead of auto-tendering the bill', function () {
    // The reported case, to the yen: ¥1,793 bill settled with a ¥2,000 note.
    // The workstation has always forwarded `tendered_amount`, but this endpoint
    // neither accepted nor kept it and overwrote the tender with `amount + tip`,
    // so Cloud's copy read "tendered 1,793 / change 0" — and every document
    // reprinted from Cloud repeated it.
    $order = makeWsPaymentOrder(1793);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1793,
        'tendered_amount' => 2000,
    ])->assertCreated();

    $payment = OrderPayment::where('customer_order_id', $order->id)->firstOrFail();
    expect((float) $payment->amount)->toBe(1793.0);
    expect((float) $payment->tendered_amount)->toBe(2000.0);
    expect((float) $payment->change_amount)->toBe(207.0);
});

it('adds the tip on top of the counted cash when both are sent', function () {
    $order = makeWsPaymentOrder(1500);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
        'tip_amount' => 200,
        'tendered_amount' => 2000,
    ])->assertCreated();

    $payment = OrderPayment::where('customer_order_id', $order->id)->firstOrFail();
    // change = tendered − amount − tip = 2000 − 1500 − 200.
    expect((float) $payment->tendered_amount)->toBe(2000.0);
    expect((float) $payment->change_amount)->toBe(300.0);
});

it('degrades a short or absent tender to the auto-tender rather than stranding the money', function () {
    // Everything reaching this endpoint is cash already in the drawer, and the
    // workstation dead-letters a 4xx instead of retrying — so a tender that
    // cannot cover the charge must NOT be rejected. It falls back to
    // `amount + tip`, exactly the pre-existing #817 B4 behaviour.
    $order = makeWsPaymentOrder(1000);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1000,
        'tip_amount' => 100,
        'tendered_amount' => 900, // < amount + tip
    ])->assertCreated();

    $payment = OrderPayment::where('customer_order_id', $order->id)->firstOrFail();
    expect((float) $payment->tendered_amount)->toBe(1100.0);
    expect((float) $payment->change_amount)->toBe(0.0);
});

/** Give the device branch a MAIN till with a session in the given status. */
function openBranchShift(string $status = 'open'): TillSession
{
    $till = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    $session = TillSession::factory()->create([
        'status' => $status,
        'till_id' => $till->id,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    $till->update(['current_session_id' => $session->id]);

    return $session;
}

it('plan-044 — attributes a synced payment to the branch open shift (the NULL-attribution bug fix)', function () {
    $shift = openBranchShift('open');
    $order = makeWsPaymentOrder(1500);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
    ])->assertCreated();

    $payment = OrderPayment::where('customer_order_id', $order->id)->first();
    // Before the fix this was NULL — silently excluded from every per-shift report.
    expect($payment->till_session_id)->toBe($shift->id);
});

it('plan-044 — falls back to NULL attribution when no shift is open (true gap payment)', function () {
    $order = makeWsPaymentOrder(1500);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
    ])->assertCreated();

    expect(OrderPayment::where('customer_order_id', $order->id)->first()->till_session_id)->toBeNull();
});

it('plan-044 R6 — honours a same-branch in-progress till_session_id supplied on sync UP', function () {
    // Two in-progress shifts can never co-exist on one till (v1), but a payment
    // may legitimately carry a `closing` shift id (drawer still open, plan-030).
    $shift = openBranchShift('closing');
    $order = makeWsPaymentOrder(1500);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
        'till_session_id' => $shift->id,
    ])->assertCreated();

    expect(OrderPayment::where('customer_order_id', $order->id)->first()->till_session_id)->toBe($shift->id);
});

it('plan-044 R6 — drops a foreign/terminal session id to the branch fallback, never 422', function () {
    $openShift = openBranchShift('open');

    // A session id from a DIFFERENT branch — a forged/stale sync payload.
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $otherTill = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => $otherBranch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $foreignShift = TillSession::factory()->open()->create([
        'till_id' => $otherTill->id,
        'branch_id' => $otherBranch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $order = makeWsPaymentOrder(1500);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
        'till_session_id' => $foreignShift->id,
    ])->assertCreated(); // tolerant: no 422 on attribution

    // Cross-branch id dropped; attributed to THIS branch's open shift instead.
    $payment = OrderPayment::where('customer_order_id', $order->id)->first();
    expect($payment->till_session_id)->toBe($openShift->id)
        ->and($payment->till_session_id)->not->toBe($foreignShift->id);
});

it('rejects payment when device token is missing', function () {
    $order = makeWsPaymentOrder(1000);

    $this->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1000,
    ])->assertUnauthorized();
});

it('rejects payment when caller is not a workstation device', function () {
    $kioskToken = Str::random(64);
    Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $kioskToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $order = makeWsPaymentOrder(1000);

    $this->withHeaders([
        'Authorization' => "Bearer {$kioskToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1000,
    ])->assertForbidden();
});

it('rejects payment when the order belongs to a different branch', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $otherCustomer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $otherBranch->id,
    ]);
    $order = CustomerOrder::create([
        'order_code' => 'ORD-OTHER-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => 1000, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 1000, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'customer_id' => $otherCustomer->id,
        'branch_id' => $otherBranch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1000,
    ])->assertNotFound();
});

it('deduplicates retries on the same idempotency_key', function () {
    $order = makeWsPaymentOrder(2000);
    $key = (string) Str::uuid();

    $first = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => $key,
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1000,
    ])->assertCreated();

    $second = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => $key,
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1000,
    ]);

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1);
});

it('allows the same idempotency_key on a different order — composite UNIQUE per PR #288', function () {
    $orderA = makeWsPaymentOrder(1000);
    $orderB = makeWsPaymentOrder(1000);
    $key = (string) Str::uuid();

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => $key,
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $orderA->id,
        'payment_method' => 'cash',
        'amount' => 1000,
    ])->assertCreated();

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => $key,
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $orderB->id,
        'payment_method' => 'cash',
        'amount' => 1000,
    ])->assertCreated();

    expect(OrderPayment::where('idempotency_key', $key)->count())->toBe(2);
});

it('reports payment status after creation', function () {
    $order = makeWsPaymentOrder(1500);
    $created = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
    ])->assertCreated();

    $paymentId = $created->json('data.id');

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->getJson("/api/v1/workstation/payments/{$paymentId}/status")
        ->assertOk()
        ->assertJsonPath('data.id', $paymentId)
        ->assertJsonPath('data.status', 'succeeded');
});

it('rejects payment when method is unknown to the branch', function () {
    $order = makeWsPaymentOrder(1000);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'nonexistent_method',
        'amount' => 1000,
    ])->assertUnprocessable();
});

/**
 * #2535 B9 — mã giao dịch của thiết bị ngoại vi phải tới được Cloud.
 *
 * `reference_no` là mã 釣銭機 Glory (hoặc mã máy quẹt thẻ) mà máy trạm đã lưu
 * trong SQLite của nó. Trước bản vá, endpoint này **không đọc** trường đó, nên
 * nó dừng lại ở máy: lúc cần đối soát sổ của máy với sổ Cloud — đúng lúc nghi
 * ngờ thất thoát tiền mặt — không có gì để so.
 */
it('#2535 B9 — lưu reference_no của thiết bị ngoại vi lên Cloud', function () {
    $order = makeWsPaymentOrder(1500);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
        'reference_no' => 'GLORY-TXN-42',
    ])->assertCreated();

    expect(OrderPayment::where('customer_order_id', $order->id)->value('reference_no'))
        ->toBe('GLORY-TXN-42');
});

it('#2535 B9 — đường thanh toán không có reference_no vẫn chạy y như cũ', function () {
    $order = makeWsPaymentOrder(1500);

    $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
    ])->assertCreated();

    expect(OrderPayment::where('customer_order_id', $order->id)->value('reference_no'))->toBeNull();
});
