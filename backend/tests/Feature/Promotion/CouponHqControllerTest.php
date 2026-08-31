<?php

/**
 * Plan-019 — feature tests for HQ\CouponController.
 *
 * Covers the happy path of CRUD + pause/resume on the
 * /api/v1/hq/{brandSlug}/coupons surface.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
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
        'slug' => 'cup-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/coupons";
});

// ─── happy path ─────────────────────────────────────────────────────────

it('lists empty coupons initially', function () {
    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('creates a coupon and surfaces computed_status active', function () {
    $payload = [
        'code' => 'WELCOME10',
        'name:en' => 'Welcome 10%',
        'name:ja' => 'ようこそ10%',
        'name:vi' => 'Chào 10%',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'max_discount_cap' => 50000,
        'min_order_subtotal' => 100000,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 1,
        'valid_from' => now()->subDay()->toIso8601String(),
        'valid_until' => now()->addDays(7)->toIso8601String(),
        'status' => 'draft',
    ];

    $response = $this->actingAs($this->user)
        ->postJson($this->base, $payload)
        ->assertCreated()
        ->assertJsonPath('data.code', 'WELCOME10')
        ->assertJsonPath('data.discount_type', 'percent')
        ->assertJsonPath('data.computed_status', 'active');

    $couponId = $response->json('data.id');
    expect($couponId)->not->toBeNull();
    expect(Coupon::where('id', $couponId)->where('code', 'WELCOME10')->exists())->toBeTrue();
});

it('uppercases the code on create', function () {
    $payload = baseCouponPayload(code: 'lowercode_test');

    $this->actingAs($this->user)
        ->postJson($this->base, $payload)
        ->assertCreated()
        ->assertJsonPath('data.code', 'LOWERCODE_TEST');
});

it('rejects invalid discount_value when percent', function () {
    $payload = baseCouponPayload(code: 'BADPERCENT');
    $payload['discount_type'] = 'percent';
    $payload['discount_value'] = 150;

    $this->actingAs($this->user)
        ->postJson($this->base, $payload)
        ->assertStatus(422);
});

it('rejects max_discount_cap when discount_type is fixed', function () {
    $payload = baseCouponPayload(code: 'BADCAP');
    $payload['discount_type'] = 'fixed';
    $payload['discount_value'] = 50000;
    $payload['max_discount_cap'] = 30000;

    $this->actingAs($this->user)
        ->postJson($this->base, $payload)
        ->assertStatus(422);
});

it('rejects duplicate codes within the same brand', function () {
    $payload = baseCouponPayload(code: 'DUPCODE');

    $this->actingAs($this->user)
        ->postJson($this->base, $payload)
        ->assertCreated();

    $this->actingAs($this->user)
        ->postJson($this->base, $payload)
        ->assertStatus(422);
});

it('shows a coupon by id', function () {
    $coupon = makeStoredCoupon($this->brand, $this->orgId, code: 'SHOWN');

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$coupon->id}")
        ->assertOk()
        ->assertJsonPath('data.code', 'SHOWN')
        ->assertJsonPath('data.applicable_branch_ids', []);
});

it('updates an unredeemed coupon', function () {
    $coupon = makeStoredCoupon($this->brand, $this->orgId, code: 'UPDME');

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$coupon->id}", [
            'name:en' => 'Updated name',
            'usage_limit_total' => 200,
        ])
        ->assertOk()
        ->assertJsonPath('data.usage_limit_total', 200);
});

it('soft-deletes an unredeemed coupon', function () {
    $coupon = makeStoredCoupon($this->brand, $this->orgId, code: 'DELME');

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$coupon->id}")
        ->assertNoContent();

    expect(Coupon::withTrashed()->where('id', $coupon->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
});

it('refuses to delete a redeemed coupon', function () {
    // The policy denies delete when times_used > 0 (so the FE can hide
    // the button up-front). The service-level 409
    // coupon_already_redeemed_use_pause_instead only fires when a caller
    // bypasses the policy. We assert the policy path (403) here.
    $coupon = makeStoredCoupon($this->brand, $this->orgId, code: 'REDEEMED', timesUsed: 3);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$coupon->id}")
        ->assertForbidden();

    expect(Coupon::where('id', $coupon->id)->whereNull('deleted_at')->exists())->toBeTrue();
});

it('pauses and resumes a coupon', function () {
    $coupon = makeStoredCoupon($this->brand, $this->orgId, code: 'PAUSEME');

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$coupon->id}/pause")
        ->assertOk()
        ->assertJsonPath('data.status', 'paused')
        ->assertJsonPath('data.computed_status', 'paused');

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$coupon->id}/resume")
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.computed_status', 'active');
});

// ─── auth ───────────────────────────────────────────────────────────────

it('returns 401 without auth', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

// ─── branch whitelist validation (plan-019 fix) ─────────────────────────

it('accepts applicable_branch_ids when every branch belongs to the brand', function () {
    $branchA = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $branchB = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $payload = baseCouponPayload(code: 'WHITELIST');
    $payload['applicable_branch_ids'] = [$branchA->id, $branchB->id];

    $this->actingAs($this->user)
        ->postJson($this->base, $payload)
        ->assertCreated()
        ->assertJsonPath('data.applicable_branch_ids', fn ($ids) => count($ids) === 2);
});

it('rejects applicable_branch_ids when a branch belongs to a different brand', function () {
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $branchOwn = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $branchOther = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);

    $payload = baseCouponPayload(code: 'CROSSBRAND');
    $payload['applicable_branch_ids'] = [$branchOwn->id, $branchOther->id];

    $this->actingAs($this->user)
        ->postJson($this->base, $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('applicable_branch_ids');
});

// ─── redemptions list ───────────────────────────────────────────────────

it('paginates redemption history with flat customer_name + order_code', function () {
    $coupon = makeStoredCoupon($this->brand, $this->orgId, code: 'HISTORY');

    // Seed two redemptions: one with a customer, one walk-in.
    $shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $shop->id,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);
    $order1 = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $shop->id,
        'order_code' => 'ORD-TEST-001',
        'customer_id' => $customer->id,
    ]);
    $order2 = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $shop->id,
        'order_code' => 'ORD-TEST-002',
        'customer_id' => null,
    ]);

    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'customer_order_id' => $order1->id,
        'customer_id' => $customer->id,
        'discount_applied_amount' => 12000,
        'coupon_snapshot' => ['code' => 'HISTORY'],
        'redeemed_at' => now()->subHour(),
        'redeemed_via' => 'pos',
    ]);
    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'customer_order_id' => $order2->id,
        'customer_id' => null,
        'discount_applied_amount' => 8000,
        'coupon_snapshot' => ['code' => 'HISTORY'],
        'redeemed_at' => now(),
        'redeemed_via' => 'pos',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}/{$coupon->id}/redemptions")
        ->assertOk();

    // Most-recent first.
    $response
        ->assertJsonPath('data.0.order_code', 'ORD-TEST-002')
        ->assertJsonPath('data.0.customer_name', null)
        ->assertJsonPath('data.0.discount_applied_amount', '8000.00')
        ->assertJsonPath('data.1.order_code', 'ORD-TEST-001')
        ->assertJsonPath('data.1.customer_name', 'Ada Lovelace')
        ->assertJsonPath('data.1.discount_applied_amount', '12000.00')
        ->assertJsonPath('meta.total', 2);
});

// ─── helpers ────────────────────────────────────────────────────────────

function baseCouponPayload(string $code): array
{
    return [
        'code' => $code,
        'name:en' => 'Test',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'min_order_subtotal' => 0,
        'usage_limit_per_customer' => 0,
        'valid_from' => now()->subDay()->toIso8601String(),
        'valid_until' => now()->addDays(7)->toIso8601String(),
    ];
}

function makeStoredCoupon(Brand $brand, string $orgId, string $code, int $timesUsed = 0): Coupon
{
    return Coupon::factory()->create([
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
        'code' => $code,
        'discount_type' => 'percent',
        'discount_value' => 10,
        'max_discount_cap' => 50000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => $timesUsed,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);
}
