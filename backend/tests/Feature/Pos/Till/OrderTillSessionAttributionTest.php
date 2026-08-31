<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use App\Services\Customer\CustomerOrderService;
use App\Services\Order\Contracts\OrderStatusVocabulary;
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * Plan-044 (issue #503) — Order ↔ Till Session attribution + carry-over.
 *
 * Two axes exercised here (Cloud backend slice, S1–S3):
 *   - Stamp-at-creation (R1): CustomerOrderService::insertOrder stamps the
 *     branch's currently-OPEN shift; a `closing` shift or none → NULL.
 *   - Carry-over (R2/R4): opening a shift adopts the branch's active orders and
 *     the gap payments (bounded below by the prior shift's end) into the new
 *     shift, leaving terminal orders and historical NULL payments untouched.
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
});

/** Minimal branch-scoped order header in a given status, optionally pre-stamped. */
function makeAttributionOrder(object $ctx, string $status, ?string $tillSessionId = null): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-A'.random_int(10000, 99999),
        'order_type' => 'dine_in',
        'status' => $status,
        'subtotal' => 0,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 0,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'till_session_id' => $tillSessionId,
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
    ]);
}

// ── Resolver: open-only ─────────────────────────────────────────────────────

it('resolves the open shift id for the branch, and null while closing', function () {
    $svc = app(TillSessionService::class);

    expect($svc->openSessionIdForBranch($this->branch->id))->toBeNull();

    $open = TillSession::factory()->open()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $this->till->update(['current_session_id' => $open->id]);

    expect($svc->openSessionIdForBranch($this->branch->id))->toBe($open->id);

    // A `closing` shift must NOT stamp new orders (open-only, unlike payments).
    $open->update(['status' => 'closing']);
    expect($svc->openSessionIdForBranch($this->branch->id))->toBeNull();
});

// ── Stamp at creation ───────────────────────────────────────────────────────

it('stamps a new order with the open shift on creation', function () {
    $open = TillSession::factory()->open()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $this->till->update(['current_session_id' => $open->id]);

    $order = app(CustomerOrderService::class)->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'order_type' => 'dine_in',
    ]);

    expect($order->till_session_id)->toBe($open->id);
});

it('leaves a gap order (no open shift) unattributed at creation', function () {
    $order = app(CustomerOrderService::class)->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'order_type' => 'dine_in',
    ]);

    expect($order->till_session_id)->toBeNull();
});

// ── Carry-over on shift open ────────────────────────────────────────────────

it('does NOT carry active orders or sweep gap payments on open (queue removed, R2)', function () {
    $cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);

    // Prior shift ended at T_prev; its till lock is released (settled).
    $tPrev = now()->subHours(2);
    TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'closed_at' => $tPrev,
    ]);
    $this->till->update(['current_session_id' => null]);

    // Gap orders (active, unattributed) + one terminal order that must stay put.
    $gapOpen = makeAttributionOrder($this, 'open');
    $gapPaying = makeAttributionOrder($this, 'paying');
    $closed = makeAttributionOrder($this, 'closed');
    $voided = makeAttributionOrder($this, 'voided');

    // A gap payment created AFTER T_prev (adopt) and a historical NULL payment
    // created BEFORE T_prev (must never be swallowed).
    $makePayment = function (Carbon $when) use ($cashMethod) {
        $order = makeAttributionOrder($this, 'paying');

        return OrderPayment::factory()->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $cashMethod->id,
            'amount' => 1000,
            'status' => 'succeeded',
            'refund_of_id' => null,
            'till_session_id' => null,
            'received_by_id' => (string) Str::uuid(),
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'brand_id' => $this->brand->id,
            'created_at' => $when,
        ]);
    };
    $gapPayment = $makePayment(now()->subMinutes(30));      // after T_prev
    $historicalPayment = $makePayment($tPrev->copy()->subHour()); // before T_prev

    // Open the next shift WITHOUT a claim → R2 performs no carry-over at all.
    app(TillSessionService::class)->open([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opening_counts' => [],
    ]);

    // Nothing is re-stamped: active orders stay NULL (served in the next shift),
    // terminal orders stay frozen, and no gap payment is swept (attribution is by
    // explicit claim only — see CarryOverGapPaymentSweepTest).
    expect($gapOpen->fresh()->till_session_id)->toBeNull()
        ->and($gapPaying->fresh()->till_session_id)->toBeNull()
        ->and($closed->fresh()->till_session_id)->toBeNull()
        ->and($voided->fresh()->till_session_id)->toBeNull()
        ->and($gapPayment->fresh()->till_session_id)->toBeNull()
        ->and($historicalPayment->fresh()->till_session_id)->toBeNull();
});

it('the CLAIM window bound uses the abandoned shift end — a payment inside the abandoned shift cannot be claimed (R2)', function () {
    $cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);

    // Two prior sessions on this till:
    //   • an OLD settled shift that closed 5h ago, and
    //   • a MORE RECENT shift that was ABANDONED 2h ago (abandoned_at only —
    //     no closed_at / expired_at). The true end of the last prior shift is
    //     the abandon boundary (2h ago), NOT the old close (5h ago).
    $oldClose = now()->subHours(5);
    $abandonAt = now()->subHours(2);

    TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'closed_at' => $oldClose,
    ]);
    TillSession::factory()->abandoned()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'abandoned_at' => $abandonAt,
    ]);
    $this->till->update(['current_session_id' => null]);

    $makePayment = function (Carbon $when) use ($cashMethod) {
        $order = makeAttributionOrder($this, 'paying');

        return OrderPayment::factory()->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $cashMethod->id,
            'amount' => 1000,
            'status' => 'succeeded',
            'refund_of_id' => null,
            'till_session_id' => null,
            'received_by_id' => (string) Str::uuid(),
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'brand_id' => $this->brand->id,
            'created_at' => $when,
        ]);
    };
    // Created DURING the abandoned shift (3h ago — after the old close, before
    // the abandon). Belongs to nothing; must stay NULL. With T_prev ignoring
    // abandoned_at it would be >= old-close(5h) and get wrongly swallowed.
    $insideAbandoned = $makePayment(now()->subHours(3));
    // A true gap payment created after the abandon boundary — claimable.
    $gapPayment = $makePayment(now()->subMinutes(30));

    // Both ids are offered to the claim. prev_end = the LATEST terminal marker =
    // the abandon time (2h ago), NOT the older close (5h ago). So the 3h-ago
    // payment is out of window and skipped; the 30m-ago one is claimed.
    $new = app(TillSessionService::class)->open([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opening_counts' => [],
        'claimed_gap_payment_ids' => [$insideAbandoned->id, $gapPayment->id],
        'gap_cash_held_separately_ack' => true,
    ]);

    expect($insideAbandoned->fresh()->till_session_id)->toBeNull()
        ->and($gapPayment->fresh()->till_session_id)->toBe($new->id);
});

it('re-stamp is idempotent — re-running open logic never double-mutates a stamped order', function () {
    $open = TillSession::factory()->open()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $this->till->update(['current_session_id' => $open->id]);

    // An order already stamped to the open shift.
    $order = makeAttributionOrder($this, 'open', $open->id);
    $before = $order->fresh()->updated_at;

    // The carry-over predicate (status active AND till_session_id != new) must
    // NOT touch a row already carrying the target session id.
    $affected = CustomerOrder::query()
        ->where('branch_id', $this->branch->id)
        ->whereIn('status', OrderStatusVocabulary::OPEN)
        ->where(function ($q) use ($open) {
            $q->whereNull('till_session_id')->orWhere('till_session_id', '!=', $open->id);
        })
        ->update(['till_session_id' => $open->id]);

    expect($affected)->toBe(0)
        ->and($order->fresh()->till_session_id)->toBe($open->id)
        ->and($order->fresh()->updated_at->equalTo($before))->toBeTrue();
});
