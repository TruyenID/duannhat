<?php

/**
 * plan-054 M9 (T9.1) — the regression that keeps the cashier from charging twice.
 *
 * A customer pays a PayPay QR on their own phone. Nothing tells the workstation
 * directly: no payment feed, no poke, no new endpoint. The only thing standing
 * between that payment and a cashier collecting the same money again at the
 * counter is that the money lands on a COLUMN of the order —
 * `customer_orders.paid_amount` — written by an Eloquent `update()`, which bumps
 * `updated_at`, which is exactly what the workstation's 5 s
 * `GET /workstation/orders?updated_since=<cursor>` tick selects on.
 *
 * That chain is four links long and no single one of them is about PayPay, so
 * nothing else in the suite fails if a link breaks:
 *
 *   1. recordPayPayPayment → syncLedgerCacheAndSettleIfPaid
 *   2. → refreshPaymentCacheFromLedger → $order->update(['paid_amount' => …])
 *        (WritesCustomerOrders.php — Eloquent update ⇒ updated_at bumps)
 *   3. → the order now falls inside the workstation's updated_since window
 *   4. → CustomerOrderResourceBase emits `paid_amount` + `status`
 *        UNCONDITIONALLY (no whenLoaded), so the Go client's
 *        `PaidAmount json.Number \`json:"paid_amount"\`` decodes it and
 *        pos-web renders remaining_amount = total − paid = 0.
 *
 * Silence any one of those (stop bumping the timestamp, hide `paid_amount`
 * behind a whenLoaded, drop the cursor filter) and the cashier takes cash for an
 * order that is already paid — then the workstation's sync-UP hits
 * `abort(409, "Order must be in 'checkout' or 'paying' status")` and the cash is
 * in the drawer with no row in Cloud. That is why this test exists.
 *
 * T9.2 is asserted here too: the workstation is NOT given the payment ROWS, only
 * the aggregate. That is the documented contract, identical to Stripe.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use App\Services\Customer\OrderPaymentService;
use Carbon\Carbon;
use Illuminate\Support\Str;

uses()->group('payment');

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
        'currency' => 'JPY',
    ]);

    // The PayPay currency guard reads the branch's priced currency the same way
    // the Stripe funnel does — the shop setting, not branches.currency.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'currency_code' => 'JPY',
    ]);

    $this->wsToken = Str::random(64);

    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    // T0 — the cursor the workstation is holding when the customer starts paying.
    $this->t0 = Carbon::parse('2026-07-29 10:00:00');

    $this->pull = fn (Carbon $cursor) => $this
        ->withHeader('Authorization', "Bearer {$this->wsToken}")
        ->getJson('/api/v1/workstation/orders?updated_since='.urlencode($cursor->toIso8601String()));
});

function plan054WorkstationOrder(string $orgId, string $brandId, string $branchId, float $total): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'branch_id' => $branchId,
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open->value,
        'subtotal' => $total,
        'discount_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0,
    ]);
}

it('lands a customer-web PayPay payment inside the workstation updated_since window', function () {
    // Both orders are created BEFORE the cursor, so neither is in the window to
    // begin with. Only the payment can put one there.
    $this->travelTo($this->t0->copy()->subHour());

    $paidOrder = plan054WorkstationOrder($this->orgId, $this->brand->id, $this->branch->id, 3000);
    $untouchedOrder = plan054WorkstationOrder($this->orgId, $this->brand->id, $this->branch->id, 4200);

    // ── tick at T0: the workstation sees nothing, and must see nothing ──────
    $this->travelTo($this->t0);

    $idsBefore = collect(($this->pull)($this->t0)->assertOk()->json('data'))->pluck('id')->all();

    expect($idsBefore)
        ->not->toContain($paidOrder->id)
        ->not->toContain($untouchedOrder->id);

    // ── the customer scans the QR and PayPay takes the money ────────────────
    $this->travelTo($this->t0->copy()->addMinutes(5));

    $outcome = app(OrderPaymentService::class)
        ->recordPayPayPaymentByOrderId((string) $paidOrder->id, 'tempoqr-ws-pull', 3000.0, 'JPY');

    expect($outcome['recorded'])->toBeTrue()
        ->and($outcome['stranded_amount'])->toBeNull();

    // ── next 5 s tick, SAME cursor the workstation was already holding ──────
    $response = ($this->pull)($this->t0)->assertOk();
    $rows = collect($response->json('data'));

    $row = $rows->firstWhere('id', $paidOrder->id);

    expect($row)->not->toBeNull(
        'the PayPay payment did not bump updated_at — the workstation will never learn the order is paid',
    );

    // The whole point: the money reached the LAN as an aggregate on the order.
    expect((float) $row['paid_amount'])->toBe(3000.0)
        ->and((float) $row['total_amount'])->toBe(3000.0)
        // What the Go client computes for pos-web:
        // customer_order_shape.go — remaining := total_amount - paid_amount.
        ->and((float) $row['total_amount'] - (float) $row['paid_amount'])->toBe(0.0)
        // Settlement rode along: paid in full ⇒ the order is closed, so pos-web
        // does not offer it up for collection at all.
        ->and($row['status'])->toBe(CustomerOrderStatusEnum::Closed->value);

    // ── negative control ────────────────────────────────────────────────────
    // Without this the test passes even if the endpoint ignored the cursor and
    // returned the whole branch. The untouched order has not moved since T0.
    expect($rows->pluck('id')->all())->not->toContain($untouchedOrder->id);
    expect((float) $untouchedOrder->fresh()->paid_amount)->toBe(0.0);
});

it('gives the workstation the tender identity but never the PayPay payment rows', function () {
    // T9.2 said the workstation would know HOW MUCH was paid but never WHICH
    // tender paid it, and that a tender breakdown would be a new feed rather
    // than a bug here. #1282 IS that feed: a receipt for an online-paid order
    // was printing no 支払方法 line at all, because the workstation records no
    // local payment row for money confirmed in Cloud.
    //
    // What has NOT changed is the harder half of the rule: the raw rows still
    // never ship. `payment_summary` is an identity for the printed slip; the
    // workstation must not hold these as payments, since that table drives its
    // Z-report / 精算 and the plan-044 gap panel.
    $this->travelTo($this->t0->copy()->subHour());

    $order = plan054WorkstationOrder($this->orgId, $this->brand->id, $this->branch->id, 3000);

    $this->travelTo($this->t0->copy()->addMinutes(5));

    app(OrderPaymentService::class)->recordPayPayPaymentByOrderId((string) $order->id, 'tempoqr-ws-tender', 3000.0, 'JPY');

    $row = collect(($this->pull)($this->t0)->assertOk()->json('data'))->firstWhere('id', $order->id);

    expect($row)->not->toBeNull()
        ->and($row)->not->toHaveKey('payments')
        ->and((float) $row['paid_amount'])->toBe(3000.0);

    expect($row['payment_summary'])->toHaveCount(1)
        ->and((float) $row['payment_summary'][0]['amount'])->toBe(3000.0);
    // Named, one way or another — a code, or the method's own name.
    expect(trim((string) ($row['payment_summary'][0]['payment_method_code']
        ?? $row['payment_summary'][0]['payment_method_name'] ?? '')))->not->toBe('');
});

it('still surfaces a partial PayPay payment so the counter collects only the remainder', function () {
    // The order stays open — this is the case where the cashier DOES take money,
    // and taking the full total instead of the remainder is the same overcharge
    // in a quieter form.
    $this->travelTo($this->t0->copy()->subHour());

    $order = plan054WorkstationOrder($this->orgId, $this->brand->id, $this->branch->id, 5000);

    $this->travelTo($this->t0->copy()->addMinutes(5));

    app(OrderPaymentService::class)->recordPayPayPaymentByOrderId((string) $order->id, 'tempoqr-ws-partial', 2000.0, 'JPY');

    $row = collect(($this->pull)($this->t0)->assertOk()->json('data'))->firstWhere('id', $order->id);

    expect($row)->not->toBeNull()
        ->and((float) $row['paid_amount'])->toBe(2000.0)
        ->and((float) $row['total_amount'] - (float) $row['paid_amount'])->toBe(3000.0)
        ->and($row['status'])->not->toBe(CustomerOrderStatusEnum::Closed->value);
});
