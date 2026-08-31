<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Omnify\Enums\PaymentStatusEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * #816 (adjacent) — a refunded sale vanished from the shift's GROSS revenue.
 *
 * refund() flips the ORIGINAL payment to `refunded` (keeping its +X) and adds a
 * separate -X `succeeded` row. ShopTillTrackingService summed
 * `status = succeeded AND refund_of_id IS NULL`, so:
 *
 *   - `refund_of_id IS NULL` correctly dropped the -X refund row, but
 *   - `status = succeeded` ALSO dropped the +X sale once it flipped to `refunded`
 *
 * Neither row counted, and the sale disappeared from gross entirely. The
 * original flips to `refunded` for a PARTIAL refund too, so refunding a single
 * unit of a 1000 sale erased the whole 1000 from the shift's revenue.
 */
beforeEach(function () {
    // Pin the clock to midday. These fixtures build timestamps from now()±offsets
    // and then assert against a "today" filter; run them near midnight and the
    // offset lands on the previous date, so the filter legitimately finds nothing.
    // See #1091 — this is the same date-boundary class as the Sunday-only and
    // 23:59-only failures fixed in 6e124f62.
    Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

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
        'slug' => 'gross-refund-shop',
    ]);

    Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 60]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->manager, $this->orgId);

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
        'closed_at' => now()->subHour(),
        'business_date' => now()->toDateString(),
    ]);

    $this->order = CustomerOrder::create([
        'order_code' => 'ORD-'.Str::random(6),
        'order_type' => 'dine_in',
        'status' => 'closed',
        'subtotal' => 1000, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 1000, 'paid_amount' => 1000, 'total_tip' => 0,
        'opened_at' => now()->subHours(2),
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
});

/** Ledger the way refund() writes it: original flips to `refunded`, negative row added. */
function grrPayment(float $amount, string $status, ?string $refundOf = null): OrderPayment
{
    return OrderPayment::factory()->create([
        'customer_order_id' => test()->order->id,
        'payment_method_id' => test()->method->id,
        'till_session_id' => test()->session->id,
        'amount' => $amount,
        'status' => $status,
        'paid_at' => now()->subHour(),
        'refund_of_id' => $refundOf,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
    ]);
}

function grrGross(): float
{
    Cache::flush();

    return (float) test()->actingAs(test()->manager)
        ->getJson('/api/v1/shops/'.test()->shop->slug.'/till/dashboard')
        ->assertOk()
        ->json('data.kpis.settled_today.gross_total_amount');
}

it('keeps a fully-refunded sale in gross revenue', function () {
    $sale = grrPayment(1000, PaymentStatusEnum::Refunded->value);      // original, flipped
    grrPayment(-1000, PaymentStatusEnum::Succeeded->value, $sale->id); // refund row

    // The sale happened. Gross is pre-refund by definition; the outflow is
    // accounted against expected_cash, not by deleting the sale from revenue.
    expect(grrGross())->toBe(1000.0);
});

it('keeps the full sale in gross revenue after a 1-unit partial refund', function () {
    // refund() flips the original to `refunded` regardless of the refunded
    // amount, so the buggy filter erased the entire 1000 over a 1-unit refund.
    $sale = grrPayment(1000, PaymentStatusEnum::Refunded->value);
    grrPayment(-1, PaymentStatusEnum::Succeeded->value, $sale->id);

    expect(grrGross())->toBe(1000.0);
});

it('still excludes the negative refund row from gross (no double count)', function () {
    grrPayment(1000, PaymentStatusEnum::Succeeded->value); // untouched sale

    expect(grrGross())->toBe(1000.0);
});
