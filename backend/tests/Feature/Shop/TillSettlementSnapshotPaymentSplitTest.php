<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Str;

/**
 * Guards the settlement-snapshot payment split (settlementSnapshotDetail) so it
 * ties out to the shift's authoritative per-method reconcile ($paymentSums).
 *
 * The split previously filtered `status IN (pending, succeeded, confirmed)`,
 * which:
 *   - included `pending` money that never entered the drawer (overcount),
 *   - dropped `refunded` sale originals the sale shift keeps across a
 *     cross-shift refund (undercount), and
 *   - used `confirmed` — a CustomerOrder status, never a payment status.
 *
 * The snapshot's 支払方法 section must equal the gross printed beside it, so
 * it now mirrors netCollectedForOrder(): sale originals succeeded OR refunded,
 * plus this shift's succeeded refund rows.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->method = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
    $this->till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 100,
        'is_active' => true,
    ]);
    $this->session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opened_at' => now()->subHours(3),
        'closed_at' => now()->subHour(),
        'business_date' => now()->toDateString(),
    ]);
});

function snapshotDetail(TillSession $session, array $orderIds): array
{
    $service = app(TillSessionService::class);
    $method = new ReflectionMethod($service, 'settlementSnapshotDetail');
    $method->setAccessible(true);

    return $method->invoke($service, $session->fresh(), collect($orderIds));
}

function tsPayment(array $overrides): OrderPayment
{
    return OrderPayment::factory()->create(array_merge([
        'payment_method_id' => test()->method->id,
        'till_session_id' => test()->session->id,
        'organization_id' => test()->orgId,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'paid_at' => now()->subHour(),
    ], $overrides));
}

it('keeps a cross-shift-refunded sale and drops pending money from the payment split', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'closed',
        'total_amount' => 4000,
        'paid_amount' => 4000,
    ]);

    // A sale collected in THIS shift, later refunded in a FUTURE shift → the
    // original flips to `refunded` but the 3000 stayed in this drawer.
    tsPayment(['customer_order_id' => $order->id, 'amount' => 3000, 'status' => PaymentStatusEnum::Refunded->value]);
    // A normal succeeded sale.
    tsPayment(['customer_order_id' => $order->id, 'amount' => 1000, 'status' => PaymentStatusEnum::Succeeded->value]);
    // Pending money that never entered the drawer — must NOT count.
    tsPayment(['customer_order_id' => $order->id, 'amount' => 500, 'status' => PaymentStatusEnum::Pending->value]);

    $detail = snapshotDetail($this->session, [$order->id]);
    $cash = collect($detail['payments'])->firstWhere('code', 'cash');

    // 3000 (refunded original) + 1000 (succeeded) = 4000; pending excluded.
    expect($cash)->not->toBeNull()
        ->and($cash['amount'])->toBe(4000.0)
        ->and($cash['count'])->toBe(2);
});

it('nets a same-shift refund row inside the split', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'closed',
        'total_amount' => 1000,
        'paid_amount' => 700,
    ]);

    $sale = tsPayment(['customer_order_id' => $order->id, 'amount' => 1000, 'status' => PaymentStatusEnum::Refunded->value]);
    // The -300 refund row taken THIS shift (succeeded, refund_of_id set).
    tsPayment([
        'customer_order_id' => $order->id,
        'amount' => -300,
        'status' => PaymentStatusEnum::Succeeded->value,
        'refund_of_id' => $sale->id,
    ]);

    $cash = collect(snapshotDetail($this->session, [$order->id])['payments'])->firstWhere('code', 'cash');

    // 1000 - 300 = 700 net stayed in the drawer.
    expect($cash['amount'])->toBe(700.0)->and($cash['count'])->toBe(2);
});

it('classifies a voided order paid by a real payment as a paid void, not unpaid', function () {
    // A pending-only voided order is UNPAID (money never landed); a
    // refunded/succeeded one is PAID. The old `pending` term mis-read the
    // first as paid.
    $paidVoid = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'voided',
        'total_amount' => 800,
        'voided_at' => now()->subHours(2),
    ]);
    tsPayment(['customer_order_id' => $paidVoid->id, 'amount' => 800, 'status' => PaymentStatusEnum::Refunded->value]);

    $unpaidVoid = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'voided',
        'total_amount' => 600,
        'voided_at' => now()->subHours(2),
    ]);
    tsPayment(['customer_order_id' => $unpaidVoid->id, 'amount' => 600, 'status' => PaymentStatusEnum::Pending->value]);

    $voids = snapshotDetail($this->session, [$paidVoid->id, $unpaidVoid->id])['voids'];

    expect($voids['paid_count'])->toBe(1)
        ->and($voids['paid_amount'])->toBe(800.0)
        ->and($voids['unpaid_count'])->toBe(1)
        ->and($voids['unpaid_amount'])->toBe(600.0);
});
