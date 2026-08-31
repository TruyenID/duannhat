<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * Plan-054 D10 — online money is not a gap payment.
 *
 * A customer-web checkout (Stripe today, PayPay next) keeps `till_session_id`
 * NULL forever because no drawer ever collected it, which made it look exactly
 * like a gap payment to the shift-open claim panel. Claiming one stamps a shift
 * on money the cashier never held → an unexplainable variance at close →
 * close() aborts 422 VARIANCE_REASON_REQUIRED on the qr/emoney buckets and the
 * shift can no longer be closed. The panel must show, and the claim must
 * accept, ONLY drawer-side money.
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
        'till_code' => 'MAIN',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $this->cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
    $this->qrMethod = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId,
        'code' => 'paypay',
        'is_auto_confirm' => true,
    ]);

    // The gap window needs a lower bound: a prior terminal shift, till released.
    $this->prevEnd = now()->subHours(2);
    TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'closed_at' => $this->prevEnd,
    ]);
    $this->till->update(['current_session_id' => null]);
});

/** A succeeded, unattributed payment in the gap window, on the given channel. */
function d10GapPayment(object $ctx, ?string $channel, string $methodId, float $amount, ?Carbon $when = null): OrderPayment
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-D'.random_int(100000, 999999),
        'order_type' => 'dine_in',
        'status' => 'paying',
        'subtotal' => $amount, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => $amount, 'paid_amount' => 0, 'total_tip' => 0, 'opened_at' => now(),
        'branch_id' => $ctx->branch->id, 'brand_id' => $ctx->brand->id, 'organization_id' => $ctx->orgId,
    ]);

    return OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $methodId,
        'amount' => $amount,
        'channel' => $channel,
        'refund_of_id' => null,
        'till_session_id' => null,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $ctx->orgId,
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
        'created_at' => $when ?? now()->subMinutes(20),
    ]);
}

function d10OpenShift(object $ctx, array $claimedIds = [], bool $ack = false): TillSession
{
    return app(TillSessionService::class)->open([
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
        'opening_counts' => [],
        'claimed_gap_payment_ids' => $claimedIds,
        'gap_cash_held_separately_ack' => $ack,
    ]);
}

it('hides a customer_web payment from gapPreview but keeps drawer-side ones', function () {
    $online = d10GapPayment($this, PaymentChannelEnum::CustomerWeb->value, $this->qrMethod->id, 3000);
    $pos = d10GapPayment($this, PaymentChannelEnum::Pos->value, $this->cashMethod->id, 800);
    // NULL channel is the pre-#1059 drawer-side shape — must NOT be filtered out.
    $legacy = d10GapPayment($this, null, $this->cashMethod->id, 500);

    $preview = app(TillSessionService::class)->gapPreview($this->branch->id);
    $ids = array_column($preview['payments'], 'id');

    expect($ids)->not->toContain($online->id)
        ->and($ids)->toContain($pos->id)
        ->and($ids)->toContain($legacy->id)
        ->and($preview['totals']['count'])->toBe(2)
        ->and($preview['totals']['cash_amount'])->toBe(1300.0)
        ->and($preview['totals']['non_cash_amount'])->toBe(0.0);
});

it('does not surface a kiosk gap payment as online money', function () {
    $kiosk = d10GapPayment($this, PaymentChannelEnum::Kiosk->value, $this->cashMethod->id, 1200);

    $preview = app(TillSessionService::class)->gapPreview($this->branch->id);

    expect(array_column($preview['payments'], 'id'))->toContain($kiosk->id);
});

it('refuses to attribute a claimed customer_web payment to the opening shift', function () {
    // A stale pos-web build (or a plain curl) can post an id the preview never
    // showed it — the wall has to stand where the money is attributed too.
    $online = d10GapPayment($this, PaymentChannelEnum::CustomerWeb->value, $this->qrMethod->id, 3000);
    $drawer = d10GapPayment($this, null, $this->cashMethod->id, 800);

    $session = d10OpenShift($this, [$online->id, $drawer->id], ack: true);

    expect($online->fresh()->till_session_id)->toBeNull()
        ->and($drawer->fresh()->till_session_id)->toBe($session->id)
        ->and($session->gapPaymentsClaimed)->toBe(1);
});
