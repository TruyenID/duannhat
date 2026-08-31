<?php

/**
 * GET /api/v1/shops/{shopSlug}/debts/{customer} — the rows a settlement can
 * actually reference.
 *
 * `index()` groups by customer, which answers "who owes and how much" and is
 * precisely not enough to collect: settling posts
 * `metadata.settles_payment_id`, and that id exists nowhere in an aggregate.
 * The POS "Tra cứu nợ" dialog could therefore list debtors and then do nothing
 * about them.
 *
 * The two views MUST agree — the whole point of the detail view is that the
 * cashier settles the same rows the total was built from — so both run off one
 * extracted predicate and these tests assert the agreement, not just the shape.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function detailOrg(string $slug): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $shop = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'slug' => $slug,
        'is_active' => true,
    ]);

    return ['orgId' => $orgId, 'brand' => $brand, 'shop' => $shop];
}

function onAccountMethod(string $orgId, Branch $shop): PaymentMethod
{
    $pm = PaymentMethod::factory()->create([
        'code' => 'debt-'.Str::random(4),
        'organization_id' => $orgId,
        'branch_id' => $shop->id,
    ]);
    DB::table('payment_methods')->where('id', $pm->id)->update(['type' => 'on_account']);

    return $pm;
}

/** One debt of `$amount`; returns [orderId, paymentId]. */
function seedDebtRow(array $org, Customer $customer, PaymentMethod $pm, float $amount): array
{
    $order = CustomerOrder::factory()->create([
        'organization_id' => $org['orgId'],
        'brand_id' => $org['brand']->id,
        'branch_id' => $org['shop']->id,
        'customer_id' => $customer->id,
        'status' => 'closed',
    ]);

    $paymentId = (string) Str::uuid();
    DB::table('order_payments')->insert([
        'id' => $paymentId,
        'payment_code' => strtoupper(Str::random(10)),
        'amount' => $amount,
        'tip_amount' => 0,
        'status' => 'succeeded',
        'payment_method_id' => $pm->id,
        'customer_order_id' => $order->id,
        'branch_id' => $org['shop']->id,
        'brand_id' => $org['brand']->id,
        'organization_id' => $org['orgId'],
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$order->id, $paymentId];
}

function callerFor(array $org): User
{
    $user = User::factory()->create(['console_organization_id' => $org['orgId']]);
    grantOrgAccess($user, $org['orgId']);

    return $user;
}

it('returns one row per debt, carrying the payment id a settlement must reference', function () {
    $org = detailOrg('debt-detail-1');
    $pm = onAccountMethod($org['orgId'], $org['shop']);
    $customer = Customer::factory()->create();

    [$orderA, $payA] = seedDebtRow($org, $customer, $pm, 120000);
    [$orderB, $payB] = seedDebtRow($org, $customer, $pm, 35000);

    $rows = $this->actingAs(callerFor($org))
        ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/{$customer->id}")
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(2);

    $byPayment = collect($rows)->keyBy('payment_id');
    expect($byPayment)->toHaveKey($payA)
        ->and($byPayment)->toHaveKey($payB)
        // The order id matters as much as the payment id: the caller needs to
        // know WHICH order the debt sits on to reason about it at all.
        ->and($byPayment[$payA]['order_id'])->toBe($orderA)
        ->and($byPayment[$payB]['order_id'])->toBe($orderB)
        ->and((float) $byPayment[$payA]['amount'])->toBe(120000.0)
        ->and((float) $byPayment[$payB]['amount'])->toBe(35000.0);
});

it('sums to exactly what the grouped list reports', function () {
    $org = detailOrg('debt-detail-2');
    $pm = onAccountMethod($org['orgId'], $org['shop']);
    $customer = Customer::factory()->create();

    seedDebtRow($org, $customer, $pm, 120000);
    seedDebtRow($org, $customer, $pm, 35000);

    $user = callerFor($org);

    $grouped = collect(
        $this->actingAs($user)
            ->getJson("/api/v1/shops/{$org['shop']->slug}/debts")
            ->assertOk()
            ->json('data')
    )->firstWhere('customer_id', $customer->id);

    $detail = $this->actingAs($user)
        ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/{$customer->id}")
        ->assertOk()
        ->json('data');

    // If these two ever disagree, one of the screens is lying about money.
    expect(collect($detail)->sum(fn ($d) => (float) $d['net_amount']))
        ->toBe((float) $grouped['open_debt_total'])
        ->and(count($detail))->toBe((int) $grouped['open_debt_count']);
});

it('never leaks another organizations debts', function () {
    $a = detailOrg('debt-detail-a');
    $b = detailOrg('debt-detail-b');

    $custB = Customer::factory()->create();
    seedDebtRow($b, $custB, onAccountMethod($b['orgId'], $b['shop']), 99000);

    // Probing org B's customer through org A's shop must return nothing —
    // the branch filter lives in the shared predicate, so the detail route
    // inherits the tenant isolation `index()` has.
    $this->actingAs(callerFor($a))
        ->getJson("/api/v1/shops/{$a['shop']->slug}/debts/{$custB->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('drops a debt that a settlement already cleared', function () {
    $org = detailOrg('debt-detail-3');
    $pm = onAccountMethod($org['orgId'], $org['shop']);
    $customer = Customer::factory()->create();

    [, $payA] = seedDebtRow($org, $customer, $pm, 120000);
    [$orderB] = seedDebtRow($org, $customer, $pm, 35000);

    // A live settlement pointing at debt A.
    DB::table('order_payments')->insert([
        'id' => (string) Str::uuid(),
        'payment_code' => strtoupper(Str::random(10)),
        'amount' => 120000,
        'tip_amount' => 0,
        'status' => 'succeeded',
        'payment_method_id' => $pm->id,
        'customer_order_id' => $orderB,
        'branch_id' => $org['shop']->id,
        'brand_id' => $org['brand']->id,
        'organization_id' => $org['orgId'],
        'metadata' => json_encode(['settles_payment_id' => $payA]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rows = $this->actingAs(callerFor($org))
        ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/{$customer->id}")
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('payment_id'))->not->toContain($payA);
});

it('marks a partially refunded debt un-settleable instead of pretending it can be paid', function () {
    $org = detailOrg('debt-detail-4');
    $pm = onAccountMethod($org['orgId'], $org['shop']);
    $customer = Customer::factory()->create();

    [$orderA, $payA] = seedDebtRow($org, $customer, $pm, 120000);

    // A ¥1,000 goodwill reversal against the debt.
    DB::table('order_payments')->insert([
        'id' => (string) Str::uuid(),
        'payment_code' => strtoupper(Str::random(10)),
        'amount' => -1000,
        'tip_amount' => 0,
        'status' => 'succeeded',
        'payment_method_id' => $pm->id,
        'customer_order_id' => $orderA,
        'refund_of_id' => $payA,
        'branch_id' => $org['shop']->id,
        'brand_id' => $org['brand']->id,
        'organization_id' => $org['orgId'],
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = collect(
        $this->actingAs(callerFor($org))
            ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/{$customer->id}")
            ->assertOk()
            ->json('data')
    )->firstWhere('payment_id', $payA);

    // The customer owes 119,000 — but OrderPaymentStoreRequest compares a
    // settlement against the ORIGINAL 120,000 and rejects anything else
    // (`settles_amount_mismatch`). Paying the net is refused and paying the
    // original over-collects, so the row says so rather than letting the
    // cashier find out from a 422 with the customer at the counter.
    expect($row)->not->toBeNull()
        ->and((float) $row['amount'])->toBe(120000.0)
        ->and((float) $row['net_amount'])->toBe(119000.0)
        ->and($row['is_settleable'])->toBeFalse();

    // The reversal itself is never listed as a debt of its own.
    expect(collect(
        $this->actingAs(callerFor($org))
            ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/{$customer->id}")
            ->json('data')
    ))->toHaveCount(1);
});

/*
 * Soft deletes (#1993).
 *
 * `openDebtQuery()` is built with `DB::table`, which applies no model scope, so
 * every joined table arrives unfiltered. Three of the five must be filtered and
 * TWO MUST NOT — and the two that must not are the reason these tests exist as a
 * set rather than one case: a blanket `whereNull` sweep passes the first three
 * and quietly erases every debt recorded with a retired payment method.
 */

/** Soft-delete a row by id, the way a model's `delete()` would. */
function softDelete(string $table, string $id): void
{
    DB::table($table)->where('id', $id)->update(['deleted_at' => now()]);
}

it('drops a debt whose payment row was soft-deleted', function () {
    $org = detailOrg('debt-softdel-1');
    $pm = onAccountMethod($org['orgId'], $org['shop']);
    $customer = Customer::factory()->create();

    [, $live] = seedDebtRow($org, $customer, $pm, 35000);
    [, $deleted] = seedDebtRow($org, $customer, $pm, 120000);
    softDelete('order_payments', $deleted);

    $user = callerFor($org);

    $rows = $this->actingAs($user)
        ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/{$customer->id}")
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('payment_id'))->toContain($live)
        ->and(collect($rows)->pluck('payment_id'))->not->toContain($deleted);

    // And the grouped total must move with it, or the two views disagree —
    // which is the failure mode the shared predicate exists to prevent.
    $grouped = collect(
        $this->actingAs($user)
            ->getJson("/api/v1/shops/{$org['shop']->slug}/debts")
            ->assertOk()
            ->json('data')
    )->firstWhere('customer_id', $customer->id);

    expect((float) $grouped['open_debt_total'])->toBe(35000.0)
        ->and($grouped['open_debt_count'])->toBe(1);
});

/**
 * The one that loses money instead of inventing it.
 *
 * A settlement that no longer exists cannot go on clearing a debt. Unfiltered,
 * the `whereNotExists` keeps matching the deleted row, the debt stays hidden,
 * and the only symptom is a smaller number on the screen the cashier reads out
 * before taking money — no error, nothing red.
 */
it('hands the debt back when its settlement is soft-deleted', function () {
    $org = detailOrg('debt-softdel-2');
    $pm = onAccountMethod($org['orgId'], $org['shop']);
    $customer = Customer::factory()->create();

    [, $debt] = seedDebtRow($org, $customer, $pm, 120000);
    [$liveOrder] = seedDebtRow($org, $customer, $pm, 35000);

    $settlementId = (string) Str::uuid();
    DB::table('order_payments')->insert([
        'id' => $settlementId,
        'payment_code' => strtoupper(Str::random(10)),
        'amount' => 120000,
        'tip_amount' => 0,
        'status' => 'succeeded',
        'payment_method_id' => $pm->id,
        'customer_order_id' => $liveOrder,
        'branch_id' => $org['shop']->id,
        'brand_id' => $org['brand']->id,
        'organization_id' => $org['orgId'],
        'metadata' => json_encode(['settles_payment_id' => $debt]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = callerFor($org);

    // Sanity: while the settlement is live the debt is cleared.
    expect(collect(
        $this->actingAs($user)
            ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/{$customer->id}")
            ->json('data')
    )->pluck('payment_id'))->not->toContain($debt);

    softDelete('order_payments', $settlementId);

    expect(collect(
        $this->actingAs($user)
            ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/{$customer->id}")
            ->assertOk()
            ->json('data')
    )->pluck('payment_id'))->toContain($debt);
});

/**
 * Reachable today, not hypothetical: `transportWorkstationSoftDelete` soft-deletes
 * an order from the sync-UP path with no payment guard at all (deliberately
 * lenient, so a rejection cannot strand the workstation's sync op in a retry
 * loop). `partPaid()` in this same controller already filters deleted orders;
 * the debt query did not.
 */
it('drops a debt whose order was soft-deleted', function () {
    $org = detailOrg('debt-softdel-3');
    $pm = onAccountMethod($org['orgId'], $org['shop']);
    $customer = Customer::factory()->create();

    [$order, $payment] = seedDebtRow($org, $customer, $pm, 120000);
    softDelete('customer_orders', $order);

    $user = callerFor($org);

    expect(collect(
        $this->actingAs($user)
            ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/{$customer->id}")
            ->assertOk()
            ->json('data')
    )->pluck('payment_id'))->not->toContain($payment);

    $this->actingAs($user)
        ->getJson("/api/v1/shops/{$org['shop']->slug}/debts")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/**
 * ⚠️ This one asserts the ABSENCE of a filter, on purpose.
 *
 * A debt outlives the payment method it was recorded with. A shop that retires
 * its 掛売 method — renames it, replaces it, cleans up the list — has not
 * forgiven anybody's debt, and filtering `pm.deleted_at` would erase every
 * outstanding balance in the shop at once. If a later cleanup "fixes the
 * inconsistency" by adding that filter, this test is what stops it.
 */
it('keeps a debt whose payment method was soft-deleted', function () {
    $org = detailOrg('debt-softdel-4');
    $pm = onAccountMethod($org['orgId'], $org['shop']);
    $customer = Customer::factory()->create();

    [, $payment] = seedDebtRow($org, $customer, $pm, 120000);
    softDelete('payment_methods', (string) $pm->id);

    expect(collect(
        $this->actingAs(callerFor($org))
            ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/{$customer->id}")
            ->assertOk()
            ->json('data')
    )->pluck('payment_id'))->toContain($payment);
});

/**
 * Same shape as the one above: `customers` is a LEFT JOIN used only to render
 * who owes. Filtering it would not drop the row, it would blank the name — a
 * debt attributed to nobody, which is worse than a debt attributed to a deleted
 * customer record.
 */
it('still names a debtor whose customer row was soft-deleted', function () {
    $org = detailOrg('debt-softdel-5');
    $pm = onAccountMethod($org['orgId'], $org['shop']);
    $customer = Customer::factory()->create(['first_name' => 'Nợ', 'last_name' => 'Cũ']);

    seedDebtRow($org, $customer, $pm, 120000);
    softDelete('customers', (string) $customer->id);

    $grouped = collect(
        $this->actingAs(callerFor($org))
            ->getJson("/api/v1/shops/{$org['shop']->slug}/debts")
            ->assertOk()
            ->json('data')
    )->firstWhere('customer_id', $customer->id);

    expect($grouped)->not->toBeNull()
        ->and($grouped['customer_name'])->toBe('Nợ Cũ')
        ->and((float) $grouped['open_debt_total'])->toBe(120000.0);
});

/*
 * Cửa sổ `from`/`to` là NGÀY KINH DOANH của quán (#1091 · #1993).
 *
 * Bản cũ đem thẳng chuỗi ngày so với `order_payments.created_at`, một cột UTC.
 * Ở một quán JST thì "2026-08-06" trở thành 09:00 giờ quán, nên chín tiếng đầu
 * mỗi ngày bán hàng bị đẩy sang ngày hôm trước của báo cáo — đúng khuyết tật
 * #1091 đã sửa cho `TillSession.business_date`.
 */

/** Ghi một khoản nợ tại một mốc UTC cụ thể. */
function seedDebtAt(array $org, Customer $customer, PaymentMethod $pm, float $amount, string $utc): string
{
    [, $paymentId] = seedDebtRow($org, $customer, $pm, $amount);
    DB::table('order_payments')->where('id', $paymentId)->update(['created_at' => $utc]);

    return $paymentId;
}

/**
 * Ba múi giờ, cùng một mốc UTC, ba câu trả lời khác nhau — và cả ba đều đúng
 * với ngày kinh doanh của quán đó. Một phép thử ở đúng một múi giờ sẽ xanh cả
 * khi code vẫn đang so chuỗi với UTC.
 */
it('lọc from/to theo ngày kinh doanh của chi nhánh', function (string $tz, bool $expectedInDay6) {
    $org = detailOrg('debt-bizday-'.strtolower(str_replace(['/', '_'], '-', $tz)));
    $org['shop']->forceFill(['timezone' => $tz])->save();

    $pm = onAccountMethod($org['orgId'], $org['shop']);
    $customer = Customer::factory()->create();

    // 2026-08-05 16:00 UTC = ngày 6 lúc 01:00 JST · 23:00 ngày 5 ICT · 16:00 ngày 5 UTC.
    seedDebtAt($org, $customer, $pm, 120000, '2026-08-05 16:00:00');

    // Cửa sổ `from`/`to` chỉ có ở danh sách GOM NHÓM — `show()` cố ý không nhận
    // hai tham số đó, y như trước khi tách.
    $rows = $this->actingAs(callerFor($org))
        ->getJson("/api/v1/shops/{$org['shop']->slug}/debts?from=2026-08-06&to=2026-08-06")
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('customer_id')->contains($customer->id))->toBe($expectedInDay6);
})->with([
    'Tokyo — đã sang ngày 6' => ['Asia/Tokyo', true],
    'Ho Chi Minh — vẫn là ngày 5' => ['Asia/Ho_Chi_Minh', false],
    'UTC — vẫn là ngày 5' => ['UTC', false],
]);

/**
 * Biên trên là NỬA MỞ. `to=2026-08-06` phải ôm trọn ngày 6 giờ quán, kể cả
 * 23:59 — bản cũ dùng `<=` trên một mốc 00:00 nên đánh rơi gần cả ngày cuối
 * của mọi khoảng lọc.
 */
it('to=<ngày> bao trọn ngày đó, không cắt ở 00:00', function () {
    $org = detailOrg('debt-bizday-upper');
    $org['shop']->forceFill(['timezone' => 'Asia/Tokyo'])->save();

    $pm = onAccountMethod($org['orgId'], $org['shop']);
    $customer = Customer::factory()->create();

    // 2026-08-06 14:00 UTC = 23:00 ngày 6 giờ Tokyo — vẫn trong ngày kinh doanh 6.
    seedDebtAt($org, $customer, $pm, 35000, '2026-08-06 14:00:00');
    // 2026-08-06 15:00 UTC = 00:00 ngày 7 giờ Tokyo — đã sang ngày sau.
    seedDebtAt($org, $customer, $pm, 41000, '2026-08-06 15:00:00');

    $row = collect(
        $this->actingAs(callerFor($org))
            ->getJson("/api/v1/shops/{$org['shop']->slug}/debts?from=2026-08-06&to=2026-08-06")
            ->assertOk()
            ->json('data')
    )->firstWhere('customer_id', $customer->id);

    // Chỉ khoản 23:00 giờ quán lọt vào; khoản 00:00 hôm sau thì không.
    expect($row)->not->toBeNull()
        ->and((float) $row['open_debt_total'])->toBe(35000.0)
        ->and($row['open_debt_count'])->toBe(1);
});

/*
 * Orders left part-paid — money the shop is owed that NO debt report could see.
 *
 * A customer who pays ¥10 of a ¥1,265 order and leaves owes ¥1,255, but nothing
 * was charged to their account, so the grouped list cannot show it: that query
 * keys on `payment_method.type = 'on_account'` and there is no such payment.
 * The balance surfaced in exactly one place — the payment dialog's banner —
 * and only when that same customer was served again.
 */
function seedPartPaidOrder(array $org, Customer $customer, float $total, float $paid): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $org['orgId'],
        'brand_id' => $org['brand']->id,
        'branch_id' => $org['shop']->id,
        'customer_id' => $customer->id,
        'status' => 'paying',
        'total_amount' => $total,
        'paid_amount' => $paid,
    ]);
}

it('lists orders left part-paid, with the per-order breakdown', function () {
    $org = detailOrg('part-paid-1');
    $customer = Customer::factory()->create();

    $a = seedPartPaidOrder($org, $customer, 1265, 10);
    $b = seedPartPaidOrder($org, $customer, 2596, 100);

    $rows = $this->actingAs(callerFor($org))
        ->withHeaders(['X-Shop-Slug' => $org['shop']->slug])
        ->getJson('/api/v1/pos/debts/part-paid')
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(1);
    $row = $rows[0];

    expect($row['customer_id'])->toBe($customer->id)
        ->and($row['order_count'])->toBe(2)
        // 1255 + 2496 — what the shop is actually owed on these orders.
        ->and((float) $row['total_unpaid'])->toBe(3751.0)
        ->and($row['orders'])->toHaveCount(2);

    $byOrder = collect($row['orders'])->keyBy('order_id');
    expect((float) $byOrder[$a->id]['unpaid_amount'])->toBe(1255.0)
        ->and((float) $byOrder[$b->id]['unpaid_amount'])->toBe(2496.0);
});

it('leaves the grouped on-account total untouched', function () {
    $org = detailOrg('part-paid-2');
    $customer = Customer::factory()->create();
    seedPartPaidOrder($org, $customer, 1265, 10);

    // The figure admin-web's "Công nợ khách hàng" panel sums must not move
    // because a POS screen learned to show something else. An on-account debt
    // granted deliberately is not the same obligation as an order nobody
    // finished, and one merged number could not tell them apart.
    $this->actingAs(callerFor($org))
        ->getJson("/api/v1/shops/{$org['shop']->slug}/debts")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('never leaks another organizations part-paid orders', function () {
    $a = detailOrg('part-paid-a');
    $b = detailOrg('part-paid-b');
    seedPartPaidOrder($b, Customer::factory()->create(), 5000, 100);

    $this->actingAs(callerFor($a))
        ->withHeaders(['X-Shop-Slug' => $a['shop']->slug])
        ->getJson('/api/v1/pos/debts/part-paid')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('excludes orders that are fully paid or not in paying status', function () {
    $org = detailOrg('part-paid-3');
    $customer = Customer::factory()->create();

    // Fully paid, still `paying` (awaiting confirmation) — nothing is owed.
    seedPartPaidOrder($org, $customer, 1000, 1000);
    // Owed, but the order is closed — it is not an unfinished order any more.
    CustomerOrder::factory()->create([
        'organization_id' => $org['orgId'],
        'brand_id' => $org['brand']->id,
        'branch_id' => $org['shop']->id,
        'customer_id' => $customer->id,
        'status' => 'closed',
        'total_amount' => 1000,
        'paid_amount' => 400,
    ]);

    $this->actingAs(callerFor($org))
        ->withHeaders(['X-Shop-Slug' => $org['shop']->slug])
        ->getJson('/api/v1/pos/debts/part-paid')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('routes the literal part-paid segment, not as a customer uuid', function () {
    $org = detailOrg('part-paid-4');
    seedPartPaidOrder($org, Customer::factory()->create(), 1265, 10);

    // `debts/{customer}` is declared after this route for exactly this reason.
    // If the wildcard won, the response would be the per-customer debt shape
    // (a flat `data` of debts) rather than the grouped part-paid shape.
    $body = $this->actingAs(callerFor($org))
        ->withHeaders(['X-Shop-Slug' => $org['shop']->slug])
        ->getJson('/api/v1/pos/debts/part-paid')
        ->assertOk()
        ->json();

    expect($body)->not->toHaveKey('customer_id')
        ->and($body['data'][0])->toHaveKey('order_count');
});

/*
 * #1998 — cùng dữ liệu, cửa cho QUẢN LÝ.
 *
 * `/pos/debts/part-paid` đi bằng device token: thu ngân đứng ở quầy thấy được
 * số tiền này. Người quyết định có đi đòi hay không thì đăng nhập bằng Platform
 * SSO và không có cửa nào — đó là toàn bộ nội dung #1998.
 *
 * Hai namespace tồn tại vì hai cách XÁC THỰC, không vì hai tập dữ liệu. Nên hợp
 * đồng phải khớp: lệch một trường là quản lý và thu ngân bắt đầu nói về hai con
 * số khác nhau, và không ai biết bên nào đúng.
 */
it('#1998 mở cùng số liệu part-paid cho ADMIN qua namespace shops', function () {
    $org = detailOrg('part-paid-admin');
    $customer = Customer::factory()->create();
    seedPartPaidOrder($org, $customer, 1265, 10);
    seedPartPaidOrder($org, $customer, 2596, 100);

    $rows = $this->actingAs(callerFor($org))
        ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/part-paid")
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['customer_id'])->toBe($customer->id)
        ->and((float) $rows[0]['total_unpaid'])->toBe(3751.0)
        ->and($rows[0]['orders'])->toHaveCount(2);
});

it('#1998 hai cửa trả ĐÚNG cùng một hợp đồng — lệch một trường là hai con số', function () {
    $org = detailOrg('part-paid-parity');
    $customer = Customer::factory()->create();
    seedPartPaidOrder($org, $customer, 1265, 10);

    $caller = callerFor($org);

    $pos = $this->actingAs($caller)
        ->withHeaders(['X-Shop-Slug' => $org['shop']->slug])
        ->getJson('/api/v1/pos/debts/part-paid')->assertOk()->json('data');

    $admin = $this->actingAs($caller)
        ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/part-paid")->assertOk()->json('data');

    expect($admin)->toEqual($pos);
});

it('#1998 "part-paid" KHÔNG bị wildcard {customer} nuốt mất', function () {
    // Thứ tự khai route là load-bearing: nếu `{customer}` đứng trước, đoạn
    // literal bị đọc thành một uuid khách và endpoint trả 404 (hoặc tệ hơn, một
    // danh sách rỗng trông như "không có nợ"). `pos.php` đã ghi lại cái bẫy này;
    // ghim nó để namespace thứ hai không phải học lại bằng sự cố.
    $org = detailOrg('part-paid-order');
    $customer = Customer::factory()->create();
    seedPartPaidOrder($org, $customer, 500, 100);

    $body = $this->actingAs(callerFor($org))
        ->getJson("/api/v1/shops/{$org['shop']->slug}/debts/part-paid")
        ->assertOk()
        ->json();

    // Route `show` trả một hình dạng KHÁC (chi tiết theo khách). Nếu wildcard đã
    // nuốt, ta sẽ không thấy `total_unpaid` ở đây.
    expect($body['data'][0])->toHaveKey('total_unpaid');
});
