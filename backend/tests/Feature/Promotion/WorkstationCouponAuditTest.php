<?php

/**
 * #2189 — hai nửa backend của vụ "một cột, hai nghĩa" sau #2154.
 *
 * Mục 2: từ #2154, `coupon_redemptions.discount_applied_amount` bám giá trị
 * HIỆN HÀNH — số tiền LÚC ÁP chỉ còn sống trong audit `coupon_applied`. Đường
 * `apply()` đã ghi audit đó từ đầu; đường workstation
 * (`recordWorkstationRedemption`) thì không ghi gì, nên với coupon POS/offline
 * bản gốc mất hẳn ở lần giỏ đổi đầu tiên.
 *
 * Mục 3 ĐÃ GỠ (#2318): migration backfill `2026_08_08_120000` kéo hàng sổ đổi
 * ghi trước deploy #2154 về ngữ nghĩa hiện hành. Nó bị xoá cùng 11 data
 * migration khác — dữ liệu cũ đó chỉ tồn tại trên DB chưa reset, nên phép đo
 * cũng biến mất theo. Đừng dựng lại test cho nó; nếu cần backfill lần nữa thì
 * đó là một lệnh chạy tay, không phải migration.
 */

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Services\Order\Coupon\OrderCouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->orgId = $orgId;

    $this->coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $orgId,
        'code' => 'WS400',
        'discount_type' => 'fixed',
        'discount_value' => 400,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
    ]);

    $this->makeOrder = function (array $overrides = []): CustomerOrder {
        return CustomerOrder::factory()->create(array_merge([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
        ], $overrides));
    };
});

it('workstation redemption ghi audit coupon_applied mang số tiền lúc áp', function () {
    $order = ($this->makeOrder)(['coupon_id' => $this->coupon->id, 'discount_amount' => 400]);

    app(OrderCouponService::class)->recordWorkstationRedemption(
        (string) $this->coupon->id, $order, 400.0,
    );

    $audits = AuditLog::query()
        ->where('auditable_id', $order->id)
        ->where('action', 'coupon_applied')
        ->get();

    expect($audits)->toHaveCount(1);
    $meta = $audits->first()->metadata;
    expect($meta['coupon_code'])->toBe('WS400')
        ->and($meta['discount'])->toBe('400')
        ->and($meta['via'])->toBe('workstation');
});

it('replay sync không nhân đôi audit — một redemption, một dòng audit', function () {
    $order = ($this->makeOrder)(['coupon_id' => $this->coupon->id, 'discount_amount' => 400]);
    $svc = app(OrderCouponService::class);

    $svc->recordWorkstationRedemption((string) $this->coupon->id, $order, 400.0);
    $svc->recordWorkstationRedemption((string) $this->coupon->id, $order, 400.0);

    expect(CouponRedemption::query()->where('customer_order_id', $order->id)->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('auditable_id', $order->id)
            ->where('action', 'coupon_applied')
            ->count())->toBe(1);
});
