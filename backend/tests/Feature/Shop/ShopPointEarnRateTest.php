<?php

/**
 * #1674 — tỉ lệ tích điểm ba tầng: chi nhánh ?? brand ?? mặc định hệ thống.
 *
 * Brand đặt MẶC ĐỊNH, chi nhánh KẾ THỪA hoặc tự đặt riêng — cùng khuôn với
 * `cart_timeout_minutes`. Tầng chi nhánh mới là tầng đúng cho một chuỗi bán ở
 * nhiều nước: đơn vị tiền sống ở `shop_order_settings.currency_code`, tức ở
 * CHI NHÁNH, nên chi nhánh VN của một brand Nhật ghi đè ở đây thay vì kéo cả
 * brand lệch theo.
 *
 * Ca chịu lực không phải "lưu rồi đọc lại" mà là **thứ tự tầng** và **tính
 * nguyên tử của cặp**: nửa cặp phải rơi xuống tầng dưới, chứ không được thành
 * "tỉ lệ 0 điểm" — nếu không, một dòng dữ liệu hỏng sẽ âm thầm cắt điểm của
 * khách trong khi màn hình cài đặt trông vẫn bình thường.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\User;
use App\Services\Loyalty\CustomerPointService;
use App\Services\Loyalty\ValueObjects\PointableOrder;
use Illuminate\Support\Str;

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
        'slug' => 'pe-'.Str::random(4),
        'is_active' => true,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->shop->id,
        'currency_code' => 'JPY',
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $this->customer = Customer::factory()->selfRegistered()->create();
});

/** Đơn 1.000 yên của chi nhánh này, đã bóc thành value object như listener làm. */
function pointOrderForShop(object $ctx, float $subtotal = 1000): PointableOrder
{
    $order = CustomerOrder::factory()->create([
        'customer_id' => $ctx->customer->id,
        'branch_id' => $ctx->shop->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
        'subtotal' => $subtotal,
        'discount_amount' => 0,
    ]);

    return new PointableOrder(
        orderId: (string) $order->id,
        orderCode: $order->order_code === null ? null : (string) $order->order_code,
        organizationId: (string) $order->organization_id,
        customerId: (string) $order->customer_id,
        branchId: (string) $order->branch_id,
        brandId: (string) $order->brand_id,
        totalAmount: (float) $order->total_amount,
        subtotal: (float) $order->subtotal,
        discountAmount: (float) $order->discount_amount,
    );
}

// =============================================================================
//  Thứ tự tầng
// =============================================================================

it('chi nhánh chưa đặt thì kế thừa tỉ lệ của brand', function () {
    // Brand: 100 = 2 điểm ⇒ đơn 1.000 = 20 điểm.
    $this->brand->update(['point_earn_amount' => 100, 'point_earn_points' => 2]);

    app(CustomerPointService::class)->earnForOrder(pointOrderForShop($this));

    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(20);
});

it('chi nhánh tự đặt thì thắng mặc định của brand', function () {
    $this->brand->update(['point_earn_amount' => 100, 'point_earn_points' => 2]);
    // Chi nhánh: 500 = 1 điểm ⇒ đơn 1.000 = 2 điểm, không phải 20.
    $this->shop->update(['point_earn_amount' => 500, 'point_earn_points' => 1]);

    app(CustomerPointService::class)->earnForOrder(pointOrderForShop($this));

    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(2);
});

it('không tầng nào đặt thì rơi về mặc định hệ thống theo đơn vị tiền', function () {
    // JPY mặc định 100 yên = 1 điểm ⇒ đơn 1.000 = 10 điểm.
    app(CustomerPointService::class)->earnForOrder(pointOrderForShop($this));

    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(10);
});

it('nửa cặp ở chi nhánh rơi XUỐNG brand, không thành tỉ lệ 0', function () {
    $this->brand->update(['point_earn_amount' => 100, 'point_earn_points' => 2]);
    // API chặn nửa cặp, nhưng một lần sửa tay trong DB thì không.
    $this->shop->update(['point_earn_amount' => 500, 'point_earn_points' => null]);

    app(CustomerPointService::class)->earnForOrder(pointOrderForShop($this));

    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(20);
});

// =============================================================================
//  Endpoint cài đặt chi nhánh
// =============================================================================

it('trả về cả ba cột: ghi đè của chi nhánh, mặc định brand, và giá trị hiệu lực', function () {
    $this->brand->update(['point_earn_amount' => 100, 'point_earn_points' => 1]);
    $this->shop->update(['point_earn_amount' => 500, 'point_earn_points' => 3]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/branch")
        ->assertOk()
        ->assertJsonPath('data.point_earn_points', 3)
        ->assertJsonPath('data.hq_brand_point_earn_points', 1)
        ->assertJsonPath('data.effective_point_earn_points', 3);
});

it('chi nhánh kế thừa thì effective bằng đúng giá trị của brand', function () {
    $this->brand->update(['point_earn_amount' => 100, 'point_earn_points' => 2]);

    $data = $this->actingAs($this->user)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/branch")
        ->assertOk()
        ->assertJsonPath('data.point_earn_amount', null)
        ->assertJsonPath('data.effective_point_earn_points', 2)
        ->json('data');

    expect((float) $data['effective_point_earn_amount'])->toBe(100.0);
});

it('lưu được ghi đè của chi nhánh', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/branch", [
            'point_earn_amount' => 250,
            'point_earn_points' => 3,
        ])
        ->assertOk()
        ->assertJsonPath('data.point_earn_points', 3);

    $shop = $this->shop->fresh();
    expect((float) $shop->point_earn_amount)->toBe(250.0)
        ->and($shop->point_earn_points)->toBe(3);
});

it('xoá ghi đè bằng cách gửi cả cặp là null — quay về kế thừa', function () {
    $this->brand->update(['point_earn_amount' => 100, 'point_earn_points' => 1]);
    $this->shop->update(['point_earn_amount' => 500, 'point_earn_points' => 3]);

    $data = $this->actingAs($this->user)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/branch", [
            'point_earn_amount' => null,
            'point_earn_points' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.point_earn_amount', null)
        ->json('data');

    // Ghi đè biến mất ⇒ effective bằng đúng mặc định của brand.
    expect((float) $data['effective_point_earn_amount'])->toBe(100.0)
        ->and($this->shop->fresh()->point_earn_points)->toBeNull();
});

it('từ chối nửa cặp ở chi nhánh', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/branch", [
            'point_earn_amount' => 100,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['point_earn_points']);

    expect($this->shop->fresh()->point_earn_amount)->toBeNull();
});

it('từ chối tỉ lệ 0 ở chi nhánh', function () {
    $this->actingAs($this->user)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/branch", [
            'point_earn_amount' => 0,
            'point_earn_points' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['point_earn_amount']);
});

it('không đụng tới tỉ lệ khi PATCH một cài đặt khác của chi nhánh', function () {
    $this->shop->update(['point_earn_amount' => 500, 'point_earn_points' => 3]);

    $this->actingAs($this->user)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/branch", [
            'cart_timeout_minutes' => 45,
        ])
        ->assertOk();

    expect($this->shop->fresh()->point_earn_points)->toBe(3);
});
