<?php

/**
 * /api/v1/hq/{brand}/settings/brand tests — cart_timeout_minutes (Tier 1).
 *
 * Pairs with TC-SET-CART03: BE max bound matches the FE 1..1440 range
 * advertised by the cart-timeout settings tab.
 */

use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\Organization;
use App\Models\User;
use App\Services\Shop\EffectiveOrderPolicyService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'b-'.Str::random(4),
        'is_active' => true,
        'cart_timeout_minutes' => null,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

it('returns the current brand cart_timeout_minutes', function () {
    $this->brand->update(['cart_timeout_minutes' => 30]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/settings/brand")
        ->assertOk()
        ->assertJsonPath('data.cart_timeout_minutes', 30);
});

it('persists a valid cart_timeout_minutes in range', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'cart_timeout_minutes' => 60,
        ])
        ->assertOk()
        ->assertJsonPath('data.cart_timeout_minutes', 60);

    expect($this->brand->fresh()->cart_timeout_minutes)->toBe(60);
});

it('persists null to clear the default', function () {
    $this->brand->update(['cart_timeout_minutes' => 30]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'cart_timeout_minutes' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.cart_timeout_minutes', null);
});

it('rejects values below the minimum of 1', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'cart_timeout_minutes' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cart_timeout_minutes']);
});

it('rejects values above the maximum of 1440 (TC-SET-CART03)', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'cart_timeout_minutes' => 99999,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cart_timeout_minutes']);

    expect($this->brand->fresh()->cart_timeout_minutes)->toBeNull();
});

it('accepts the inclusive max boundary of 1440', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'cart_timeout_minutes' => 1440,
        ])
        ->assertOk()
        ->assertJsonPath('data.cart_timeout_minutes', 1440);
});

it('persists customer branding fields and echoes them back', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'customer_header_logo_url' => 'https://cdn.example.test/header-logo.png',
            'customer_order_logo_url' => 'https://cdn.example.test/order-logo.png',
            'customer_order_subtitle' => 'Chọn hình thức đặt đơn',
        ])
        ->assertOk()
        ->assertJsonPath('data.customer_header_logo_url', 'https://cdn.example.test/header-logo.png')
        ->assertJsonPath('data.customer_order_logo_url', 'https://cdn.example.test/order-logo.png')
        ->assertJsonPath('data.customer_order_subtitle', 'Chọn hình thức đặt đơn');

    $this->brand->refresh();

    expect($this->brand->customer_header_logo_url)->toBe('https://cdn.example.test/header-logo.png')
        ->and($this->brand->customer_order_logo_url)->toBe('https://cdn.example.test/order-logo.png')
        ->and($this->brand->customer_order_subtitle)->toBe('Chọn hình thức đặt đơn');
});

// =============================================================================
// #1160 — brand-default prep minutes per item
// =============================================================================

it('persists the brand-default prep minutes per item and echoes the resolved value', function () {
    // Unset: no brand default → shops resolve to the system constant.
    $this->actingAs($this->user)
        ->getJson("/api/v1/hq/{$this->brand->slug}/settings/brand")
        ->assertOk()
        ->assertJsonPath('data.default_prep_minutes_per_item', null)
        ->assertJsonPath(
            'data.effective_prep_minutes_per_item',
            EffectiveOrderPolicyService::DEFAULT_PREP_MINUTES_PER_ITEM,
        );

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'default_prep_minutes_per_item' => 9,
        ])
        ->assertOk()
        ->assertJsonPath('data.default_prep_minutes_per_item', 9)
        ->assertJsonPath('data.effective_prep_minutes_per_item', 9);

    expect(BrandOrderPolicy::where('brand_id', $this->brand->id)->value('default_prep_minutes_per_item'))
        ->toBe(9);
});

it('clears the brand default with null so shops fall back to the system default', function () {
    BrandOrderPolicy::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_prep_minutes_per_item' => 9,
    ]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'default_prep_minutes_per_item' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.default_prep_minutes_per_item', null)
        ->assertJsonPath(
            'data.effective_prep_minutes_per_item',
            EffectiveOrderPolicyService::DEFAULT_PREP_MINUTES_PER_ITEM,
        );
});

it('rejects a prep time outside 0-120', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'default_prep_minutes_per_item' => 121,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['default_prep_minutes_per_item']);
});

// =============================================================================
// #1674 — tỉ lệ tích điểm: "<point_earn_amount> tiền = <point_earn_points> điểm"
// =============================================================================

it('lưu được cặp tỉ lệ tích điểm', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'point_earn_amount' => 100,
            'point_earn_points' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('data.point_earn_points', 2);

    $brand = $this->brand->fresh();
    expect((float) $brand->point_earn_amount)->toBe(100.0)
        ->and($brand->point_earn_points)->toBe(2);
});

it('từ chối nửa cặp — chỉ có tiền, không có điểm', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'point_earn_amount' => 100,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['point_earn_points']);

    expect($this->brand->fresh()->point_earn_amount)->toBeNull();
});

it('từ chối nửa cặp — chỉ có điểm, không có tiền', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'point_earn_points' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['point_earn_amount']);
});

it('từ chối 0 — tắt tích điểm phải là một hành động đọc ra được, không phải tỉ lệ 0', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'point_earn_amount' => 0,
            'point_earn_points' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['point_earn_amount']);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'point_earn_amount' => 100,
            'point_earn_points' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['point_earn_points']);
});

it('xoá cấu hình bằng cách gửi cả cặp là null', function () {
    $this->brand->update(['point_earn_amount' => 100, 'point_earn_points' => 1]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'point_earn_amount' => null,
            'point_earn_points' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.point_earn_amount', null)
        ->assertJsonPath('data.point_earn_points', null);

    expect($this->brand->fresh()->point_earn_points)->toBeNull();
});

it('không đụng tới tỉ lệ khi PATCH một cài đặt khác', function () {
    $this->brand->update(['point_earn_amount' => 100, 'point_earn_points' => 3]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
            'cart_timeout_minutes' => 45,
        ])
        ->assertOk();

    expect($this->brand->fresh()->point_earn_points)->toBe(3);
});
