<?php

/**
 * #1441 — điểm tích luỹ + đổi điểm + hạng thành viên.
 *
 *   GET  /api/v1/customer/me/points            số dư + hạng + lịch sử
 *   GET  /api/v1/customer/me/points/rewards    catalog đổi điểm
 *   POST /api/v1/customer/me/points/redeem     đổi điểm → mint coupon cá nhân
 *   GET  /api/v1/customer/me/membership        hạng + quyền lợi
 *
 * Cộng đường tích điểm tự động qua event `OrderPaid`.
 */

use App\Events\OrderPaid;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerPointEntry;
use App\Models\Organization;
use App\Models\PointReward;
use App\Models\ShopOrderSetting;
use App\Services\Loyalty\CustomerPointService;
use App\Services\Loyalty\ValueObjects\PointableOrder;
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
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'currency_code' => 'JPY',
    ]);

    $this->customer = Customer::factory()->selfRegistered()->create();
    $this->token = $this->customer->createToken('test')->plainTextToken;
});

function makePointOrder(array $attrs = []): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'customer_id' => test()->customer->id,
        'branch_id' => test()->branch->id,
        'organization_id' => test()->orgId,
        'subtotal' => 1000,
        'discount_amount' => 0,
        ...$attrs,
    ]);
}

/**
 * #1596 — `CustomerPointService` nhận {@see PointableOrder} (tám trường vô hướng)
 * thay cho model. Helper này làm đúng việc mà listener `AwardPointsOnOrderPaid`
 * làm trong production: bóc trường ra khỏi model nó đã cầm sẵn.
 */
function pointable(CustomerOrder $order): PointableOrder
{
    return new PointableOrder(
        orderId: (string) $order->id,
        orderCode: $order->order_code === null ? null : (string) $order->order_code,
        organizationId: (string) $order->organization_id,
        customerId: $order->customer_id === null ? null : (string) $order->customer_id,
        branchId: $order->branch_id === null ? null : (string) $order->branch_id,
        brandId: $order->brand_id === null ? null : (string) $order->brand_id,
        totalAmount: (float) $order->total_amount,
        subtotal: (float) $order->subtotal,
        discountAmount: (float) $order->discount_amount,
    );
}

// =============================================================================
// Tích điểm
// =============================================================================

it('tích điểm theo tỉ lệ của đơn vị tiền chi nhánh', function () {
    // JPY: 100 yên = 1 điểm ⇒ đơn 1.000 yên = 10 điểm.
    $order = makePointOrder(['subtotal' => 1000]);

    app(CustomerPointService::class)->earnForOrder(pointable($order));

    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(10);
});

it('tỉ lệ của brand thắng mặc định hệ thống', function () {
    // #1674 — "100 yên = 2 điểm" ⇒ đơn 250 yên = 5 điểm (làm tròn XUỐNG).
    // Mặc định JPY là 100 yên = 1 điểm, nên nếu brand bị bỏ qua thì ra 2.
    $this->brand->update(['point_earn_amount' => 100, 'point_earn_points' => 2]);
    $order = makePointOrder(['subtotal' => 250, 'brand_id' => $this->brand->id]);

    app(CustomerPointService::class)->earnForOrder(pointable($order));

    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(5);
});

it('rơi về mặc định hệ thống khi brand chỉ có nửa cặp', function () {
    // API chặn nửa cặp ở 422, nhưng dữ liệu cũ (hoặc một lần sửa tay trong DB)
    // vẫn có thể lọt vào. Nửa cặp KHÔNG được coi là "tỉ lệ 0 điểm" — nó phải
    // rơi về mặc định, nếu không một dòng DB hỏng sẽ âm thầm cắt điểm của khách.
    $this->brand->update(['point_earn_amount' => 500, 'point_earn_points' => null]);
    $order = makePointOrder(['subtotal' => 1000, 'brand_id' => $this->brand->id]);

    app(CustomerPointService::class)->earnForOrder(pointable($order));

    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(10);
});

it('trừ giảm giá trước khi quy đổi ra điểm', function () {
    $order = makePointOrder(['subtotal' => 1000, 'discount_amount' => 400]);

    app(CustomerPointService::class)->earnForOrder(pointable($order));

    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(6);
});

it('không tích hai lần cho cùng một đơn dù event bắn lại', function () {
    $order = makePointOrder(['subtotal' => 5000]);

    // OrderPaid bắn từ nhiều nguồn (webhook Stripe + xác nhận đồng bộ của
    // customer-web cùng đóng một đơn) — lần thứ hai phải là no-op.
    event(new OrderPaid($order));
    event(new OrderPaid($order));

    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(50)
        ->and(CustomerPointEntry::where('customer_order_id', $order->id)->count())->toBe(1);
});

it('không tích cho đơn của khách vãng lai', function () {
    $order = makePointOrder(['customer_id' => null]);

    app(CustomerPointService::class)->earnForOrder(pointable($order));

    expect(CustomerPointEntry::count())->toBe(0);
});

// =============================================================================
// Chọn không tham gia thành viên (#1780)
// =============================================================================

// Trước #1780 khối "Bạn có muốn trở thành thành viên?" ở trang đăng ký không
// ánh xạ vào gì cả — hạng suy ra từ điểm nên mọi khách đều là thành viên. Đây
// là chỗ lựa chọn đó thực sự có hiệu lực.
it('không tích điểm cho khách đã chọn không tham gia', function () {
    $this->customer->update(['loyalty_opted_in' => false]);
    $order = makePointOrder(['subtotal' => 5000]);

    app(CustomerPointService::class)->earnForOrder(pointable($order));

    expect(CustomerPointEntry::count())->toBe(0)
        ->and(app(CustomerPointService::class)->balance($this->customer))->toBe(0);
});

// Cổng nằm ở service chứ không ở controller, nên nó phải chặn cả đường event —
// đó là đường DUY NHẤT chạy trong production (`AwardPointsOnOrderPaid`).
it('cổng opt-out chặn cả đường event OrderPaid', function () {
    $this->customer->update(['loyalty_opted_in' => false]);
    $order = makePointOrder(['subtotal' => 5000]);

    event(new OrderPaid($order));

    expect(CustomerPointEntry::where('customer_order_id', $order->id)->count())->toBe(0);
});

// Khách cũ (đăng ký trước khi có cột) mang default `true` — họ vẫn tích điểm y
// như trước. Đây là lý do cột lấy default `true` chứ không phải `false`.
it('khách mặc định vẫn tích điểm như trước', function () {
    expect($this->customer->fresh()->loyalty_opted_in)->toBeTrue();

    app(CustomerPointService::class)->earnForOrder(pointable(makePointOrder(['subtotal' => 1000])));

    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(10);
});

// Bật lại được thì lời hứa "Có thể đăng ký sau" ở trang đăng ký mới là thật.
it('bật lại thành viên thì tích điểm trở lại', function () {
    $this->customer->update(['loyalty_opted_in' => false]);
    app(CustomerPointService::class)->earnForOrder(pointable(makePointOrder(['subtotal' => 1000])));
    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(0);

    $this->customer->update(['loyalty_opted_in' => true]);
    app(CustomerPointService::class)->earnForOrder(pointable(makePointOrder(['subtotal' => 1000])));

    expect(app(CustomerPointService::class)->balance($this->customer))->toBe(10);
});

it('thu hồi đúng số điểm đã tích khi đơn bị hoàn', function () {
    $order = makePointOrder(['subtotal' => 3000]);
    $points = app(CustomerPointService::class);

    $points->earnForOrder(pointable($order));
    expect($points->balance($this->customer))->toBe(30);

    $points->revokeForOrder(pointable($order));
    expect($points->balance($this->customer))->toBe(0);

    // Gọi lại không được trừ tiếp.
    $points->revokeForOrder(pointable($order));
    expect($points->balance($this->customer))->toBe(0);
});

// =============================================================================
// Đọc — số dư, hạng
// =============================================================================

it('trả về số dư, hạng và lịch sử cho khách đăng nhập', function () {
    app(CustomerPointService::class)->earnForOrder(pointable(makePointOrder(['subtotal' => 60000])));

    $this->withToken($this->token)
        ->getJson('/api/v1/customer/me/points')
        ->assertOk()
        ->assertJsonPath('data.balance', 600)
        ->assertJsonPath('data.lifetime_points', 600)
        ->assertJsonPath('data.tier.key', 'silver')      // ≥ 500
        ->assertJsonPath('data.next_tier.key', 'gold')
        ->assertJsonPath('data.points_to_next_tier', 1400)
        ->assertJsonCount(1, 'data.entries');
});

it('tiêu điểm không làm tụt hạng', function () {
    $points = app(CustomerPointService::class);
    $points->earnForOrder(pointable(makePointOrder(['subtotal' => 60000])));   // 600 điểm → silver

    $reward = PointReward::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cost_points' => 500,
        'is_active' => true,
        'discount_type' => 'fixed',
        'discount_value' => 500,
        'max_discount_cap' => null,
        'valid_days' => 30,
    ]);
    $points->redeem($this->customer, $reward);

    // Số dư còn 100 nhưng hạng vẫn silver — hạng đo mức gắn bó, không phải ví.
    expect($points->balance($this->customer))->toBe(100)
        ->and($points->tier($this->customer)['current']['key'])->toBe('silver');
});

it('trả về hạng và thang hạng ở endpoint đặc quyền thành viên', function () {
    $this->withToken($this->token)
        ->getJson('/api/v1/customer/me/membership')
        ->assertOk()
        ->assertJsonPath('data.current_tier.key', 'bronze')
        ->assertJsonPath('data.lifetime_points', 0)
        ->assertJsonCount(4, 'data.tiers');
});

it('chặn khách chưa đăng nhập', function () {
    $this->getJson('/api/v1/customer/me/points')->assertUnauthorized();
    $this->getJson('/api/v1/customer/me/coupons')->assertUnauthorized();
    $this->getJson('/api/v1/customer/me/membership')->assertUnauthorized();
});

// =============================================================================
// Đổi điểm
// =============================================================================

it('đổi điểm thì mint coupon cá nhân và trừ đúng số điểm', function () {
    app(CustomerPointService::class)->earnForOrder(pointable(makePointOrder(['subtotal' => 100000]))); // 1000 điểm

    $reward = PointReward::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cost_points' => 300,
        'is_active' => true,
        'discount_type' => 'fixed',
        'discount_value' => 500,
        'max_discount_cap' => null,
        'valid_days' => 30,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/customer/me/points/redeem', ['reward_id' => $reward->id])
        ->assertCreated()
        ->assertJsonPath('data.balance', 700);

    $code = $response->json('data.coupon.code');
    $coupon = Coupon::where('code', $code)->first();

    expect($coupon)->not->toBeNull()
        ->and($coupon->customer_id)->toBe($this->customer->id)
        ->and($coupon->point_reward_id)->toBe($reward->id)
        ->and((int) $coupon->usage_limit_total)->toBe(1);
});

it('từ chối khi không đủ điểm và không mint gì cả', function () {
    $reward = PointReward::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cost_points' => 5000,
        'is_active' => true,
        'discount_type' => 'fixed',
        'discount_value' => 500,
        'max_discount_cap' => null,
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/customer/me/points/redeem', ['reward_id' => $reward->id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'INSUFFICIENT_POINTS');

    expect(Coupon::whereNotNull('point_reward_id')->count())->toBe(0)
        ->and(CustomerPointEntry::where('kind', 'redeem')->count())->toBe(0);
});

it('từ chối phần thưởng đã tắt', function () {
    app(CustomerPointService::class)->earnForOrder(pointable(makePointOrder(['subtotal' => 100000])));

    $reward = PointReward::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cost_points' => 100,
        'is_active' => false,
        'discount_type' => 'fixed',
        'discount_value' => 500,
        'max_discount_cap' => null,
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/customer/me/points/redeem', ['reward_id' => $reward->id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'REWARD_UNAVAILABLE');
});

it('catalog chỉ hiện phần thưởng đang bật', function () {
    PointReward::factory()->create([
        'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
        'is_active' => true, 'cost_points' => 100, 'discount_type' => 'fixed',
        'discount_value' => 100, 'max_discount_cap' => null,
    ]);
    PointReward::factory()->create([
        'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
        'is_active' => false, 'cost_points' => 200, 'discount_type' => 'fixed',
        'discount_value' => 200, 'max_discount_cap' => null,
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/customer/me/points/rewards')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
