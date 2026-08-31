<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponTranslation;
use App\Models\Device;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * Workstation coupon feed (Plan: workstation full coupon parity).
 *
 * Must emit:
 *   - backend-native field names (min_order_subtotal, max_discount_cap,
 *     usage_limit_per_customer) — workstation reads these post-027.
 *   - legacy aliases (min_order_amount, max_discount, per_customer_limit)
 *     for one rollout window so workstations not yet on migration 027
 *     still ingest the row.
 *   - branches[] from coupon_branch pivot — workstation enforces
 *     CouponService::validateBranch locally so LAN-offline staff can't
 *     apply a coupon scoped to a sibling branch.
 *   - brand_id, organization_id, description — Phase A schema parity.
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
    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('emits backend-native field names + legacy aliases', function () {
    Coupon::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'WSPARITY',
        'name' => 'Parity test',
        'description' => 'For workstation parity test',
        'discount_type' => 'fixed',
        'discount_value' => 500,
        'min_order_subtotal' => 1000,
        'max_discount_cap' => 800,
        'usage_limit_per_customer' => 3,
        'usage_limit_total' => 100,
        'times_used' => 5,
        'status' => 'draft',
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/coupons')
        ->assertOk();

    $row = collect($resp->json('data'))->firstWhere('code', 'WSPARITY');
    expect($row)->not->toBeNull();

    // Backend-native names (workstation reads post-027).
    expect($row['min_order_subtotal'])->toBe(1000);
    expect($row['max_discount_cap'])->toBe(800);
    expect($row['usage_limit_per_customer'])->toBe(3);
    expect($row['description'])->toBe('For workstation parity test');
    expect($row['brand_id'])->toBe($this->brand->id);
    expect($row['organization_id'])->toBe($this->orgId);

    // Legacy aliases (rollout window) — same values, different keys.
    expect($row['min_order_amount'])->toBe(1000);
    expect($row['max_discount'])->toBe(800);
    expect($row['per_customer_limit'])->toBe(3);

    // Branches[] empty (no pivot rows) means "all branches".
    expect($row['branches'])->toBe([]);
});

it('emits branches[] for branch-scoped coupons', function () {
    $coupon = Coupon::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'BRANCHONLY',
        'status' => 'draft',
    ]);
    $coupon->branches()->attach($this->branch->id);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/coupons')
        ->assertOk();

    $row = collect($resp->json('data'))->firstWhere('code', 'BRANCHONLY');
    expect($row['branches'])->toBe([$this->branch->id]);
});

it('emits translations[] for i18n hydration', function () {
    $coupon = Coupon::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'I18NCP',
        'status' => 'draft',
    ]);
    (new CouponTranslation)->forceFill([
        'coupon_id' => $coupon->id,
        'locale' => 'ja',
        'name' => 'クーポン',
        'description' => '日本語の説明',
    ])->save();
    (new CouponTranslation)->forceFill([
        'coupon_id' => $coupon->id,
        'locale' => 'vi',
        'name' => 'Phiếu',
        'description' => 'Mô tả tiếng Việt',
    ])->save();

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/coupons')
        ->assertOk();

    $row = collect($resp->json('data'))->firstWhere('code', 'I18NCP');
    // Factory may seed a default locale row (e.g. en) — assert the two
    // we explicitly created round-trip rather than pinning the total.
    $byLocale = collect($row['translations'])->keyBy('locale');
    expect($byLocale['ja']['name'])->toBe('クーポン');
    expect($byLocale['vi']['name'])->toBe('Phiếu');
    expect($byLocale['ja']['description'])->toBe('日本語の説明');
    expect($byLocale['vi']['description'])->toBe('Mô tả tiếng Việt');
});

it('omits coupons from other organizations', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);

    Coupon::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
        'code' => 'FOREIGNORG',
        'status' => 'draft',
    ]);

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/coupons')
        ->assertOk();

    $codes = collect($resp->json('data'))->pluck('code')->all();
    expect($codes)->not->toContain('FOREIGNORG');
});

/**
 * #2118 — coupon phần trăm LẺ (12,5% · 0,5% · 7,25%) không được mất phần lẻ
 * trên đường xuống máy trạm.
 *
 * `discount_value` là `decimal(12,2)` và validation chỉ chặn `> 100` với percent
 * (`CouponStoreRequest`), nên 12,5% là coupon **tạo được từ HQ hôm nay**. Feed
 * ép `(int) round(...)` biến nó thành 13 TRƯỚC khi máy trạm nhìn thấy — lệch
 * +50 trên giỏ 10.000, và 0,5% thành 1% là **gấp đôi** khoản giảm.
 *
 * Cổng parity `coupon_math_golden.json` không bắt được vì nó truyền CÙNG một số
 * nguyên vào cả hai phía rồi so kết quả: mất mát xảy ra phía TRÊN cả hai.
 */
it('#2118: giữ phần lẻ của coupon phần trăm qua discount_value_x100', function (float $value, int $expectedX100, int $expectedLegacy) {
    Coupon::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'FRAC'.str_replace('.', '', (string) $value),
        'name' => 'Fractional percent',
        'discount_type' => 'percent',
        'discount_value' => $value,
        'min_order_subtotal' => 0,
        'max_discount_cap' => null,
        'usage_limit_per_customer' => 0,
        'usage_limit_total' => 100,
        'times_used' => 0,
        'status' => 'draft',
    ]);

    $row = collect(
        $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
            ->getJson('/api/v1/workstation/coupons')->assertOk()->json('data')
    )->firstWhere('code', 'FRAC'.str_replace('.', '', (string) $value));

    expect($row)->not->toBeNull();
    expect($row['discount_value_x100'])->toBe(
        $expectedX100,
        "{$value}% phải tới máy trạm nguyên vẹn — feed ép số nguyên là chỗ mất mát (#2118)",
    );

    // Trường CŨ giữ nguyên nghĩa: máy trạm chưa cập nhật phải chạy y hệt hôm
    // nay. Đổi nghĩa nó là lệch 100 lần trong cửa sổ giữa hai lần deploy.
    expect($row['discount_value'])->toBe($expectedLegacy);
})->with([
    'nửa phần trăm' => [0.5, 50, 1],
    'mười hai rưỡi' => [12.5, 1250, 13],
    'bảy hai lăm' => [7.25, 725, 7],
    'nguyên — không đổi gì' => [20.0, 2000, 20],
]);

it('#2118: coupon CỐ ĐỊNH có phần lẻ cũng giữ được (quán tiền tệ 2 chữ số)', function () {
    // Cùng một lỗi truyền tải, chỉ chưa ai đo: `(int) round()` cắt cụt cả loại
    // `fixed`. Một quán USD đặt coupon $5,25 đang gửi $5 xuống máy trạm.
    Coupon::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'FIXCENTS',
        'name' => 'Fixed with cents',
        'discount_type' => 'fixed',
        'discount_value' => 5.25,
        'min_order_subtotal' => 0,
        'max_discount_cap' => null,
        'usage_limit_per_customer' => 0,
        'usage_limit_total' => 100,
        'times_used' => 0,
        'status' => 'draft',
    ]);

    $row = collect(
        $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
            ->getJson('/api/v1/workstation/coupons')->assertOk()->json('data')
    )->firstWhere('code', 'FIXCENTS');

    expect($row['discount_value_x100'])->toBe(525);
    expect($row['discount_value'])->toBe(5);
});
