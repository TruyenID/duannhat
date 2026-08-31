<?php

/**
 * Plan-019 — feature tests for the shop apply / release coupon flow.
 *
 * Endpoints:
 *   POST   /api/v1/shops/{shopSlug}/orders/{customerOrder}/apply-coupon
 *   DELETE /api/v1/shops/{shopSlug}/orders/{customerOrder}/coupon
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'apply-coupon-shop',
        'is_active' => true,
    ]);

    $managerRole = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    // Create an open order via the same endpoint a real client would use.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();

    $this->order = CustomerOrder::first();

    // Bypass the menu / addItems flow by manually setting subtotal — the
    // apply path reads $order->subtotal and computes against it. This
    // keeps the test focused on the coupon contract rather than item
    // pricing.
    $this->order->update([
        'subtotal' => 200000,
        'total_amount' => 200000,
    ]);
});

// ─── apply happy path ───────────────────────────────────────────────────

it('applies a percent coupon and updates discount + total', function () {
    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'WELCOME10',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'max_discount_cap' => 50000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'welcome10', // case-insensitive
        ])
        ->assertOk()
        ->assertJsonPath('data.coupon_code_snapshot', 'WELCOME10')
        ->assertJsonPath('data.discount_amount', '20000.00')
        ->assertJsonPath('meta.applied_coupon.code', 'WELCOME10');

    expect($coupon->fresh()->times_used)->toBe(1);
    expect(CouponRedemption::where('customer_order_id', $this->order->id)->whereNull('released_at')->count())->toBe(1);
});

it('clamps a percent coupon at max_discount_cap over HTTP', function () {
    // #2125 §A — trần `max_discount_cap` chỉ được đo ở unit test
    // (`CouponServiceTest`). Đường HTTP có dựng trần (WELCOME10, cap 50k) nhưng
    // 10% của 200k = 20k, tức KHÔNG ca nào chạm trần: mọi ca đều xanh kể cả khi
    // trần bị bỏ hẳn khỏi đường apply.
    //
    // Ca này cố ý đặt trần THẤP HƠN số tiền chưa kẹp (50% của 200k = 100k so với
    // trần 30k) nên hai giá trị khác nhau rõ rệt — bài test phân biệt được
    // "có kẹp" với "không kẹp", chứ không chỉ khẳng định endpoint trả 200.
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'HALFCAPPED',
        'discount_type' => 'percent',
        'discount_value' => 50,
        'max_discount_cap' => 30000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => null,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'HALFCAPPED',
        ])
        ->assertOk()
        ->assertJsonPath('data.discount_amount', '30000.00')
        // Tiền thật khách trả, không chỉ ô discount: 200k − 30k. Thiếu vế này
        // thì trần có thể đúng ở một cột mà tổng vẫn trừ 100k.
        ->assertJsonPath('data.total_amount', '170000.00');

    // Số tiền ghi vào sổ đổi (`coupon_redemptions`) phải là số ĐÃ kẹp — đây là
    // con số mọi báo cáo coupon đọc, và nó được ghi ở một câu lệnh khác với
    // câu ghi `customer_orders.discount_amount`.
    expect((float) CouponRedemption::where('customer_order_id', $this->order->id)
        ->whereNull('released_at')
        ->value('discount_applied_amount'))->toBe(30000.0);
});

it('applies a fixed coupon clamping to subtotal when needed', function () {
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'BIGFIXED',
        'discount_type' => 'fixed',
        'discount_value' => 999999, // > subtotal
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'usage_limit_total' => null,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'BIGFIXED',
        ])
        ->assertOk()
        ->assertJsonPath('data.discount_amount', '200000.00');
});

it('rejects with coupon_paused', function () {
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'PAUSED',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'min_order_subtotal' => 0,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'paused',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'PAUSED',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'coupon_paused');
});

it('rejects with coupon_min_subtotal_not_met', function () {
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'MIN500K',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'min_order_subtotal' => 500000,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'MIN500K',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'coupon_min_subtotal_not_met');
});

it('rejects with coupon_not_found', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'DOES_NOT_EXIST',
        ])
        ->assertStatus(404)
        ->assertJsonPath('error_code', 'coupon_not_found');
});

it('rejects with customer_required when usage_limit_per_customer > 0 and no customer_id', function () {
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'PERCUST',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'min_order_subtotal' => 0,
        'usage_limit_per_customer' => 1,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'PERCUST',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'customer_required');
});

it('rejects with coupon_already_used_by_customer when limit reached', function () {
    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'ONEPER',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'min_order_subtotal' => 0,
        'usage_limit_per_customer' => 1,
        'usage_limit_total' => 100,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    // Pre-existing redemption by this customer.
    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'customer_order_id' => (string) Str::uuid(), // fake order id, ok for unique guard
        'customer_id' => $this->customer->id,
        'discount_applied_amount' => 1000,
        'coupon_snapshot' => ['code' => 'ONEPER'],
        'redeemed_at' => now(),
        'redeemed_via' => 'pos',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'ONEPER',
            'customer_id' => $this->customer->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'coupon_already_used_by_customer');
});

it('rejects with coupon_exhausted when usage_limit_total reached', function () {
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'DEADCOUPON',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 5,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'times_used' => 5,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'DEADCOUPON',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'coupon_exhausted');
});

// ─── replace flow ───────────────────────────────────────────────────────

it('releases the previous coupon when applying a new one (atomic replace)', function () {
    $first = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'FIRST',
        'discount_type' => 'fixed',
        'discount_value' => 10000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);
    $second = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'SECOND',
        'discount_type' => 'fixed',
        'discount_value' => 30000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", ['code' => 'FIRST'])
        ->assertOk();
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", ['code' => 'SECOND'])
        ->assertOk()
        ->assertJsonPath('data.coupon_code_snapshot', 'SECOND')
        ->assertJsonPath('data.discount_amount', '30000.00');

    expect($first->fresh()->times_used)->toBe(0); // released
    expect($second->fresh()->times_used)->toBe(1); // applied
});

// ─── release ────────────────────────────────────────────────────────────

it('releases the coupon and decrements times_used', function () {
    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'RELEASEME',
        'discount_type' => 'fixed',
        'discount_value' => 10000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", ['code' => 'RELEASEME'])
        ->assertOk();

    expect($coupon->fresh()->times_used)->toBe(1);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/coupon")
        ->assertOk()
        ->assertJsonPath('data.coupon_code_snapshot', null)
        ->assertJsonPath('data.discount_amount', '0.00');

    expect($coupon->fresh()->times_used)->toBe(0);
    expect(CouponRedemption::where('customer_order_id', $this->order->id)->whereNull('released_at')->count())->toBe(0);
});

it('rejects release when no coupon is applied', function () {
    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/coupon")
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'no_coupon_applied');
});

it('allows re-applying after a prior release (sweeps orphan soft-released row)', function () {
    // Regression for plan-019 production bug: order ORD-2026-2417 had a
    // released redemption row (released_at stamped, row preserved for audit
    // per Decision 5) but coupon_id was cleared. Re-applying any coupon
    // blew up with UniqueConstraintViolationException → caught and mapped
    // to `order_not_modifiable`. The fix sweeps the orphan released row
    // before INSERT in apply().
    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'REAPPLY',
        'discount_type' => 'fixed',
        'discount_value' => 10000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    // 1. apply
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", ['code' => 'REAPPLY'])
        ->assertOk();

    // 2. release (default soft-release: stamps released_at, keeps the row)
    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/coupon")
        ->assertOk();

    // Sanity: the soft-released row is still there.
    expect(CouponRedemption::where('customer_order_id', $this->order->id)->whereNotNull('released_at')->count())
        ->toBe(1);

    // 3. apply the SAME (or any) coupon again — must succeed, not 409.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", ['code' => 'REAPPLY'])
        ->assertOk()
        ->assertJsonPath('data.coupon_code_snapshot', 'REAPPLY')
        ->assertJsonPath('data.discount_amount', '10000.00');

    // Orphan row swept; new active row in its place.
    expect(CouponRedemption::where('customer_order_id', $this->order->id)->count())->toBe(1);
    expect(CouponRedemption::where('customer_order_id', $this->order->id)->whereNull('released_at')->count())->toBe(1);
});
