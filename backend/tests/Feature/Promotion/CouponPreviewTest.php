<?php

/**
 * Plan-019 — feature tests for the customer preview endpoint.
 *
 * POST /api/v1/customer/coupons/preview is read-only — it never
 * increments times_used and never inserts a redemption row. Coupon-
 * domain failures come back as is_valid:false + error_code (NOT 4xx).
 */

use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Organization;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->branchId = (string) Str::uuid();
    DB::table('branches')->insert([
        'id' => $this->branchId,
        'console_branch_id' => $this->branchId,
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id ?? Str::uuid(),
        'code' => 'TEST',
        'slug' => 'test-branch-'.Str::random(4),
        'name' => 'Test Branch',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->endpoint = '/api/v1/customer/coupons/preview';
});

it('returns is_valid:true with discount_applied_amount when coupon matches', function () {
    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'PREVIEWOK',
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

    $this->postJson($this->endpoint, [
        'code' => 'previewok',
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchId,
        'subtotal' => 200000,
    ])
        ->assertOk()
        ->assertJsonPath('data.is_valid', true)
        ->assertJsonPath('data.code', 'PREVIEWOK')
        ->assertJsonPath('data.discount_applied_amount', 20000);

    expect($coupon->fresh()->times_used)->toBe(0); // never increments
});

it('returns is_valid:false with coupon_not_found when code does not exist', function () {
    $this->postJson($this->endpoint, [
        'code' => 'NOPE',
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchId,
        'subtotal' => 100000,
    ])
        ->assertOk()
        ->assertJsonPath('data.is_valid', false)
        ->assertJsonPath('data.error_code', 'coupon_not_found');
});

it('returns is_valid:false with coupon_min_subtotal_not_met', function () {
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'MINSUB',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'min_order_subtotal' => 200000,
        'usage_limit_per_customer' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->postJson($this->endpoint, [
        'code' => 'MINSUB',
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchId,
        'subtotal' => 100000,
    ])
        ->assertOk()
        ->assertJsonPath('data.is_valid', false)
        ->assertJsonPath('data.error_code', 'coupon_min_subtotal_not_met')
        ->assertJsonPath('data.meta.min_required', 200000);
});

it('returns is_valid:false with coupon_paused', function () {
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'PAUSED',
        'discount_type' => 'fixed',
        'discount_value' => 50000,
        'min_order_subtotal' => 0,
        'usage_limit_per_customer' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'paused',
    ]);

    $this->postJson($this->endpoint, [
        'code' => 'PAUSED',
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchId,
        'subtotal' => 200000,
    ])
        ->assertOk()
        ->assertJsonPath('data.is_valid', false)
        ->assertJsonPath('data.error_code', 'coupon_paused');
});

it('returns is_valid:false with coupon_expired', function () {
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'EXPIRED',
        'discount_type' => 'fixed',
        'discount_value' => 50000,
        'min_order_subtotal' => 0,
        'usage_limit_per_customer' => 0,
        'valid_from' => now()->subDays(10),
        'valid_until' => now()->subDay(),
        'status' => 'draft',
    ]);

    $this->postJson($this->endpoint, [
        'code' => 'EXPIRED',
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchId,
        'subtotal' => 200000,
    ])
        ->assertOk()
        ->assertJsonPath('data.is_valid', false)
        ->assertJsonPath('data.error_code', 'coupon_expired');
});

it('returns 422 on payload validation failure (NOT a coupon-domain error)', function () {
    // Missing brand_id, branch_id, subtotal.
    $this->postJson($this->endpoint, ['code' => 'X'])
        ->assertStatus(422);
});
