<?php

/**
 * #1700 — admin nhìn thấy ai đã đổi điểm.
 *
 *   GET /api/v1/hq/{brand}/customers/{id}/points        — sổ điểm một khách
 *   GET /api/v1/hq/{brand}/point-rewards/redemptions    — nhật ký cả brand
 *   GET /api/v1/hq/{brand}/customers                    — cột "Điểm"
 *   GET /api/v1/hq/{brand}/coupons?search=<đúng mã>     — lối tra theo mã
 *
 * Bốn cái bẫy được ghim ở đây, cái nào cũng đã suýt viết ngược:
 *
 *   1. PHẠM VI BRAND đi qua `point_rewards.brand_id`, KHÔNG qua
 *      `customers.organization_id`. Khách tự đăng ký có `organization_id
 *      = NULL` (BR-PT04 — ví điểm toàn cục), nên lọc theo khách sẽ giấu gần
 *      như MỌI lượt đổi. Đây là lỗi im lặng: màn hình vẫn 200, chỉ là rỗng.
 *   2. `redemptions` phải đứng TRƯỚC `{pointReward}` trong route file, không
 *      thì binding nuốt nó như một uuid và trả 404.
 *   3. Tình trạng coupon là ba từ của người vận hành (unused/used/expired),
 *      không phải `computeStatus()` (draft/exhausted) — một tấm chưa ai tiêu
 *      mà hiện "draft" thì không ai đọc được.
 *   4. Lọc theo NGÀY phải quy ra mốc UTC theo múi giờ brand. Lượt đổi lúc
 *      08:00 giờ Hà Nội là 01:00 UTC — `whereDate` trên cột UTC sẽ ném nó
 *      sang đúng ngày hôm đó nhưng một lượt lúc 23:30 Hà Nội (16:30 UTC) thì
 *      không, và sai lệch chỉ lộ ra ở vài giờ quanh nửa đêm.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerPointEntry;
use App\Models\Organization;
use App\Models\PointReward;
use App\Models\User;
use App\Omnify\Enums\PointEntryKindEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'rl-'.Str::random(4),
        'is_active' => true,
    ]);

    // Chi nhánh Việt Nam: đồng hồ vận hành của brand này là Asia/Ho_Chi_Minh,
    // KHÔNG phải Asia/Tokyo mặc định. Bẫy số 4 chỉ lộ ra khi hai cái khác nhau.
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'shop-'.Str::random(4),
        'timezone' => 'Asia/Ho_Chi_Minh',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $this->reward = PointReward::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cost_points' => 100,
        'discount_type' => 'fixed',
        'discount_value' => 2500,
        'max_discount_cap' => null,
        'stock_quantity' => null,
        'is_active' => true,
    ]);

    $this->hqCustomers = "/api/v1/hq/{$this->brand->slug}/customers";
    $this->shopCustomers = "/api/v1/shops/{$this->branch->slug}/customers";
    $this->shopRedemptions = "/api/v1/shops/{$this->branch->slug}/point-rewards/redemptions";
    $this->hqRedemptions = "/api/v1/hq/{$this->brand->slug}/point-rewards/redemptions";
    $this->hqCoupons = "/api/v1/hq/{$this->brand->slug}/coupons";
});

/**
 * Một lượt đổi hoàn chỉnh: coupon cá nhân + bút toán trừ điểm trỏ vào nó.
 *
 * Dựng tay thay vì gọi `CustomerPointService::redeem()` có chủ ý — test này
 * kiểm ĐƯỜNG ĐỌC. Đi qua đường ghi thì mỗi lần muốn một tấm đã hết hạn lại
 * phải du hành thời gian, và một lỗi ở đường ghi sẽ làm đỏ cả test đọc.
 */
function redemption(Customer $customer, PointReward $reward, array $coupon = [], ?CarbonImmutable $at = null): CustomerPointEntry
{
    $card = Coupon::factory()->create([
        'brand_id' => $reward->brand_id,
        'organization_id' => $reward->organization_id,
        'customer_id' => $customer->id,
        'point_reward_id' => $reward->id,
        'usage_limit_total' => 1,
        'usage_limit_per_customer' => 1,
        'times_used' => 0,
        'status' => 'draft',
        'valid_from' => now(),
        'valid_until' => now()->addDays(30),
        ...$coupon,
    ]);

    $entry = CustomerPointEntry::create([
        'customer_id' => $customer->id,
        'organization_id' => $reward->organization_id,
        'point_reward_id' => $reward->id,
        'coupon_id' => $card->id,
        'kind' => PointEntryKindEnum::Redeem->value,
        'points' => -1 * (int) $reward->cost_points,
    ]);

    // `created_at` KHÔNG fillable — truyền vào `create()` là bị nuốt im lặng
    // và mọi dòng ra đúng "bây giờ", làm test lọc-theo-ngày xanh vì lý do sai.
    if ($at !== null) {
        CustomerPointEntry::query()->whereKey($entry->id)->update(['created_at' => $at]);
        $entry->refresh();
    }

    return $entry;
}

/**
 * Khách THUỘC tổ chức — khách do nhân viên tạo, và cũng là khách tự đăng ký
 * kể từ #1505 (đăng ký qua slug cửa hàng thì `attachRegistrationBranch()` đóng
 * dấu org/brand/branch). Đây là dạng khách mà màn chi tiết ở HQ mở được.
 */
function orgCustomer(array $attrs = []): Customer
{
    return Customer::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        ...$attrs,
    ]);
}

function earned(Customer $customer, int $points, ?string $orgId = null): CustomerPointEntry
{
    return CustomerPointEntry::create([
        'customer_id' => $customer->id,
        'organization_id' => $orgId ?? test()->orgId,
        'kind' => PointEntryKindEnum::Earn->value,
        'points' => $points,
    ]);
}

// ─── Sổ điểm của một khách ──────────────────────────────────────────────

it('trả số dư, tổng tích luỹ và sổ điểm của một khách', function () {
    $customer = orgCustomer();
    earned($customer, 3400);
    earned($customer, 1700);
    redemption($customer, $this->reward);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->hqCustomers}/{$customer->id}/points")
        ->assertOk();

    // Số dư = SUM(points) = 3400 + 1700 - 100. Tổng tích luỹ KHÔNG trừ lượt
    // đổi: tiêu điểm không làm khách tụt hạng.
    $response->assertJsonPath('data.balance', 5000)
        ->assertJsonPath('data.lifetime_points', 5100)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonCount(3, 'data.entries');
});

it('gắn tình trạng dùng được / đã tiêu / hết hạn vào từng mã đã đổi', function () {
    $customer = orgCustomer();

    redemption($customer, $this->reward, ['code' => 'PTUNUSED1']);
    redemption($customer, $this->reward, ['code' => 'PTUSED001', 'times_used' => 1]);
    redemption($customer, $this->reward, [
        'code' => 'PTEXPIRE1',
        'valid_from' => now()->subDays(40),
        'valid_until' => now()->subDay(),
    ]);

    $entries = collect(
        $this->actingAs($this->user)
            ->getJson("{$this->hqCustomers}/{$customer->id}/points")
            ->assertOk()
            ->json('data.entries')
    )->keyBy(fn (array $e) => $e['coupon']['code']);

    expect($entries['PTUNUSED1']['coupon']['status'])->toBe('unused')
        ->and($entries['PTUSED001']['coupon']['status'])->toBe('used')
        ->and($entries['PTEXPIRE1']['coupon']['status'])->toBe('expired');
});

it('không cho admin của tổ chức khác đọc sổ điểm', function () {
    $customer = orgCustomer();
    earned($customer, 500);

    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $intruder = User::factory()->create(['console_organization_id' => $otherOrgId]);
    grantOrgAccess($intruder, $otherOrgId);

    $this->actingAs($intruder)
        ->getJson("{$this->hqCustomers}/{$customer->id}/points")
        ->assertForbidden();
});

it('KHÔNG mở sổ điểm của khách chưa gắn tổ chức — và nhật ký là đường bù lại', function () {
    // Ghim một GIỚI HẠN CÓ THẬT, không phải một tính năng.
    //
    // `authorizeOrganization()` so `customers.organization_id` với org của
    // admin, nên một khách `organization_id = NULL` thì 403 — y hệt endpoint
    // `show` đã có từ trước, và `CustomerService::list` cũng lọc theo org nên
    // khách đó còn không xuất hiện trong danh sách khách của HQ. Nới chỗ này
    // là một quyết định về việc brand được nhìn thấy khách của ai, không phải
    // một chi tiết kỹ thuật — nên nó nằm ngoài #1700.
    //
    // Từ #1505 khách đăng ký qua slug cửa hàng đã được đóng dấu org, nên đây
    // là dữ liệu cũ. Và cho dữ liệu cũ thì NHẬT KÝ đổi thưởng vẫn thấy đủ:
    // tên, điện thoại, email, phần thưởng, mã — đó là đường bù.
    $unattached = Customer::factory()->selfRegistered()->create(['phone' => '0336909454']);
    redemption($unattached, $this->reward, ['code' => 'PTORPHAN01']);

    $this->actingAs($this->user)
        ->getJson("{$this->hqCustomers}/{$unattached->id}/points")
        ->assertForbidden();

    $this->actingAs($this->user)
        ->getJson($this->hqRedemptions)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.customer.phone', '0336909454')
        ->assertJsonPath('data.0.coupon.code', 'PTORPHAN01');
});

// ─── Nhật ký đổi thưởng của brand ───────────────────────────────────────

it('liệt kê lượt đổi của khách tự đăng ký — phạm vi đi qua brand của phần thưởng', function () {
    // BẪY 1. `organization_id` của khách này là NULL. Lọc theo khách thì kết
    // quả rỗng và không ai biết là sai.
    $customer = Customer::factory()->selfRegistered()->create([
        'first_name' => 'Truyền',
        'last_name' => 'Lê',
        'phone' => '0336909454',
    ]);
    expect($customer->organization_id)->toBeNull();

    redemption($customer, $this->reward, ['code' => 'PTWMYSBM7V']);

    $this->actingAs($this->user)
        ->getJson($this->hqRedemptions)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.coupon.code', 'PTWMYSBM7V')
        ->assertJsonPath('data.0.customer.phone', '0336909454')
        ->assertJsonPath('data.0.points', -100)
        ->assertJsonPath('meta.timezone', 'Asia/Ho_Chi_Minh');
});

it('không để lọt lượt đổi của brand khác', function () {
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'other-'.Str::random(4),
    ]);
    $otherReward = PointReward::factory()->create([
        'brand_id' => $otherBrand->id,
        'organization_id' => $this->orgId,
        'cost_points' => 100,
    ]);

    $customer = Customer::factory()->selfRegistered()->create();
    redemption($customer, $this->reward, ['code' => 'PTMINE0001']);
    redemption($customer, $otherReward, ['code' => 'PTTHEIRS01']);

    $this->actingAs($this->user)
        ->getJson($this->hqRedemptions)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.coupon.code', 'PTMINE0001');
});

it('bỏ qua bút toán tích điểm — nhật ký này chỉ nói về lượt đổi', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    earned($customer, 3400);
    redemption($customer, $this->reward);

    $this->actingAs($this->user)
        ->getJson($this->hqRedemptions)
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('lọc theo tình trạng mã', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    redemption($customer, $this->reward, ['code' => 'PTUNUSED1']);
    redemption($customer, $this->reward, ['code' => 'PTUSED001', 'times_used' => 1]);
    redemption($customer, $this->reward, [
        'code' => 'PTEXPIRE1',
        'valid_from' => now()->subDays(40),
        'valid_until' => now()->subDay(),
    ]);

    $cases = ['unused' => 'PTUNUSED1', 'used' => 'PTUSED001', 'expired' => 'PTEXPIRE1'];

    foreach ($cases as $status => $expectedCode) {
        $this->actingAs($this->user)
            ->getJson("{$this->hqRedemptions}?coupon_status={$status}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.coupon.code', $expectedCode);
    }
});

it('lọc theo phần thưởng và tìm theo mã hoặc số điện thoại khách', function () {
    $customer = Customer::factory()->selfRegistered()->create(['phone' => '0336909454']);
    $other = Customer::factory()->selfRegistered()->create(['phone' => '0900000001']);

    $secondReward = PointReward::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cost_points' => 500,
    ]);

    redemption($customer, $this->reward, ['code' => 'PTWMYSBM7V']);
    redemption($other, $secondReward, ['code' => 'PTOTHER001']);

    $this->actingAs($this->user)
        ->getJson("{$this->hqRedemptions}?point_reward_id={$secondReward->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.coupon.code', 'PTOTHER001');

    $this->actingAs($this->user)
        ->getJson("{$this->hqRedemptions}?search=PTWMYSBM7V")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.customer.phone', '0336909454');

    $this->actingAs($this->user)
        ->getJson("{$this->hqRedemptions}?search=0900000001")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.coupon.code', 'PTOTHER001');
});

it('lọc theo ngày của brand, không theo ngày UTC', function () {
    // BẪY 4. 23:30 ngày 03/08 giờ Hà Nội = 16:30 UTC cùng ngày; còn 00:30
    // ngày 04/08 giờ Hà Nội = 17:30 UTC ngày 03/08 — nghĩa là hai lượt cách
    // nhau một tiếng nằm ở HAI ngày kinh doanh nhưng CÙNG một ngày UTC. Lọc
    // "ngày 03/08" mà trả về cả hai là dấu hiệu đang đọc lịch UTC.
    $customer = Customer::factory()->selfRegistered()->create();

    redemption($customer, $this->reward, ['code' => 'PTLATE0001'],
        CarbonImmutable::parse('2026-08-03 23:30', 'Asia/Ho_Chi_Minh')->utc());
    redemption($customer, $this->reward, ['code' => 'PTNEXTDAY1'],
        CarbonImmutable::parse('2026-08-04 00:30', 'Asia/Ho_Chi_Minh')->utc());

    $this->actingAs($this->user)
        ->getJson("{$this->hqRedemptions}?date_from=2026-08-03&date_to=2026-08-03")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.coupon.code', 'PTLATE0001');

    $this->actingAs($this->user)
        ->getJson("{$this->hqRedemptions}?date_from=2026-08-04&date_to=2026-08-04")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.coupon.code', 'PTNEXTDAY1');
});

it('vẫn hiện lượt đổi sau khi phần thưởng bị xoá mềm', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    redemption($customer, $this->reward, ['code' => 'PTGONE0001']);

    $this->reward->delete();

    // Sổ cái mới là thứ nói "đã có người tiêu 100 điểm ở đây" — xoá phần
    // thưởng không được xoá dấu vết đó, và cũng không được xoá TÊN của nó.
    $this->actingAs($this->user)
        ->getJson($this->hqRedemptions)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.coupon.code', 'PTGONE0001')
        ->assertJsonPath('data.0.reward.name', $this->reward->name);
});

/*
 * #1713 mục 2 — `history()` eager-load `pointReward` trần, mà `PointReward`
 * xoá mềm ⇒ trước khi sửa, dòng "đổi thưởng" trả `reward_name: null` và ô tên
 * trống trơn. Nhật ký đổi thưởng thì vẫn hiện đủ (đã `withTrashed` từ #1700) —
 * cùng một sự kiện, hai màn nói hai kiểu.
 *
 * Ghim CẢ HAI đầu ra, ở hai test riêng: bản sửa nằm trong hàm DÙNG CHUNG, nên
 * việc khách cũng thấy tên là lựa chọn có chủ ý chứ không phải tác dụng phụ —
 * và một test gộp sẽ không nói được điều đó khi nó đỏ.
 *
 * Hai test tách nhau còn vì lý do cơ học: `actingAs()` bám vào instance test,
 * nên gọi endpoint HQ trước rồi gọi endpoint khách trong CÙNG một test thì
 * `user('customer')` giải ra `User` của admin và chết ở TypeError.
 */
it('sổ điểm ở HQ giữ tên phần thưởng đã xoá mềm', function () {
    $rewardName = $this->reward->name;
    expect($rewardName)->not->toBeNull();

    $customer = orgCustomer();
    redemption($customer, $this->reward, ['code' => 'PTGONE0002']);
    $this->reward->delete();

    $this->actingAs($this->user)
        ->getJson("{$this->hqCustomers}/{$customer->id}/points")
        ->assertOk()
        ->assertJsonPath('data.entries.0.reward_name', $rewardName);
});

it('sổ điểm của chính khách cũng giữ tên phần thưởng đã xoá mềm', function () {
    $rewardName = $this->reward->name;

    $customer = orgCustomer();
    $token = $customer->createToken('test')->plainTextToken;
    redemption($customer, $this->reward, ['code' => 'PTGONE0003']);
    $this->reward->delete();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/me/points')
        ->assertOk()
        ->assertJsonPath('data.entries.0.reward_name', $rewardName);
});

// ─── Cột "Điểm" ở danh sách khách ───────────────────────────────────────

it('kèm số dư điểm vào danh sách khách hàng HQ', function () {
    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    earned($customer, 3400);
    redemption($customer, $this->reward);

    $this->actingAs($this->user)
        ->getJson($this->hqCustomers)
        ->assertOk()
        ->assertJsonPath('data.0.point_balance', 3300);
});

it('trả 0 chứ không phải null cho khách chưa có bút toán nào', function () {
    Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($this->user)
        ->getJson($this->hqCustomers)
        ->assertOk()
        ->assertJsonPath('data.0.point_balance', 0);
});

// ─── Lối tra coupon theo mã (#1441 vẫn đứng) ────────────────────────────

it('giữ coupon cá nhân ngoài danh sách coupon của HQ', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    redemption($customer, $this->reward, ['code' => 'PTWMYSBM7V']);
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'WELCOME10',
        'point_reward_id' => null,
        'customer_id' => null,
    ]);

    $this->actingAs($this->user)
        ->getJson($this->hqCoupons)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'WELCOME10');
});

it('tìm được coupon cá nhân khi gõ ĐÚNG NGUYÊN mã', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    redemption($customer, $this->reward, ['code' => 'PTWMYSBM7V']);

    $this->actingAs($this->user)
        ->getJson("{$this->hqCoupons}?search=PTWMYSBM7V")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'PTWMYSBM7V');

    // Gõ thường cũng ra — khách đọc mã qua điện thoại, không ai gõ hoa.
    $this->actingAs($this->user)
        ->getJson("{$this->hqCoupons}?search=ptwmysbm7v")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('KHÔNG kéo coupon cá nhân vào khi chỉ gõ một mảnh mã', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    redemption($customer, $this->reward, ['code' => 'PTWMYSBM7V']);

    // "PTWM" là tiền tố hợp lệ về hình thức nhưng không phải cả mã. Nếu chỗ
    // này trả về 1 dòng thì bộ lọc đã thành `like`, và vài tuần sau màn coupon
    // của HQ chỉ còn là nhật ký đổi điểm — đúng cái #1441 chặn.
    $this->actingAs($this->user)
        ->getJson("{$this->hqCoupons}?search=PTWM")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('cho phép xem cả coupon cá nhân khi xin rõ include_point_rewards', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    redemption($customer, $this->reward, ['code' => 'PTWMYSBM7V']);

    $this->actingAs($this->user)
        ->getJson("{$this->hqCoupons}?include_point_rewards=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'PTWMYSBM7V');
});

// ─── Phạm vi SHOP (#1718) ───────────────────────────────────────────────
//
// Cửa hàng thấy CÙNG dữ liệu với HQ, không phải một lát cắt theo chi nhánh —
// vì một lượt đổi điểm không gắn chi nhánh nào (khách bấm ở customer-web). Cái
// khác duy nhất là ĐỒNG HỒ tính bộ lọc ngày: shop biết chính xác chi nhánh
// mình nên không phải đoán như `clockBranchIdForBrand()` bên HQ.
//
// `meta.scope = 'brand'` là hợp đồng để màn hình ghi nhãn — nếu bỏ đi, người
// trực quầy đọc con số rồi tưởng đó là điểm tích tại quán mình.

it('cửa hàng đọc được sổ điểm của khách, kèm nhãn phạm vi brand', function () {
    $customer = orgCustomer();
    earned($customer, 3400);
    redemption($customer, $this->reward, ['code' => 'PTSHOP0001']);

    $this->actingAs($this->user)
        ->getJson("{$this->shopCustomers}/{$customer->id}/points")
        ->assertOk()
        ->assertJsonPath('data.balance', 3300)
        ->assertJsonPath('data.entries.0.coupon.code', 'PTSHOP0001')
        ->assertJsonPath('data.entries.0.coupon.status', 'unused')
        ->assertJsonPath('meta.scope', 'brand');
});

it('cửa hàng đọc được nhật ký đổi thưởng của cả brand', function () {
    $customer = Customer::factory()->selfRegistered()->create(['phone' => '0336909454']);
    redemption($customer, $this->reward, ['code' => 'PTSHOP0002']);

    $this->actingAs($this->user)
        ->getJson($this->shopRedemptions)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.coupon.code', 'PTSHOP0002')
        ->assertJsonPath('data.0.customer.phone', '0336909454')
        ->assertJsonPath('meta.scope', 'brand')
        // Đồng hồ là của CHÍNH chi nhánh này, không phải mặc định Asia/Tokyo.
        ->assertJsonPath('meta.timezone', 'Asia/Ho_Chi_Minh');
});

it('cửa hàng KHÔNG thấy lượt đổi của brand khác', function () {
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'other-'.Str::random(4),
    ]);
    $otherReward = PointReward::factory()->create([
        'brand_id' => $otherBrand->id,
        'organization_id' => $this->orgId,
        'cost_points' => 100,
    ]);

    $customer = Customer::factory()->selfRegistered()->create();
    redemption($customer, $this->reward, ['code' => 'PTSHOPMINE']);
    redemption($customer, $otherReward, ['code' => 'PTSHOPTHRS']);

    $this->actingAs($this->user)
        ->getJson($this->shopRedemptions)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.coupon.code', 'PTSHOPMINE');
});

it('cửa hàng lọc nhật ký theo ngày bằng đồng hồ CHI NHÁNH CỦA MÌNH', function () {
    // Khác bản HQ ở chỗ này: HQ phải đoán chi nhánh để lấy múi giờ, shop thì
    // biết chắc. 23:30 ngày 03/08 giờ Hà Nội và 00:30 ngày 04/08 giờ Hà Nội là
    // hai ngày kinh doanh nhưng CÙNG một ngày UTC.
    $customer = Customer::factory()->selfRegistered()->create();

    redemption($customer, $this->reward, ['code' => 'PTSHOPLATE'],
        CarbonImmutable::parse('2026-08-03 23:30', 'Asia/Ho_Chi_Minh')->utc());
    redemption($customer, $this->reward, ['code' => 'PTSHOPNEXT'],
        CarbonImmutable::parse('2026-08-04 00:30', 'Asia/Ho_Chi_Minh')->utc());

    $this->actingAs($this->user)
        ->getJson("{$this->shopRedemptions}?date_from=2026-08-03&date_to=2026-08-03")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.coupon.code', 'PTSHOPLATE');
});

it('kèm số dư điểm vào danh sách khách của cửa hàng', function () {
    $customer = orgCustomer();
    earned($customer, 900);

    $this->actingAs($this->user)
        ->getJson($this->shopCustomers)
        ->assertOk()
        ->assertJsonPath('data.0.point_balance', 900);
});

it('không cho admin của tổ chức khác đọc sổ điểm qua đường cửa hàng', function () {
    $customer = orgCustomer();
    earned($customer, 500);

    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $intruder = User::factory()->create(['console_organization_id' => $otherOrgId]);
    grantOrgAccess($intruder, $otherOrgId);

    $this->actingAs($intruder)
        ->getJson("{$this->shopCustomers}/{$customer->id}/points")
        ->assertForbidden();
});
