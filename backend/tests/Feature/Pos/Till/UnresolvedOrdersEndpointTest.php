<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * #2726 — mặt HTTP của `GET /api/v1/pos/till/unresolved-orders` (#2696).
 *
 * `UnresolvedOrdersAtShiftOpenTest` gọi THẲNG service, nên toàn bộ chuỗi
 * `auth.sso_or_device` → `throttle:pos` → `ResolvePosShop` và phép cách ly chi
 * nhánh của `ResolvesShopContext::bindShopContext` không có gì đỏ khi hồi quy —
 * trong khi anh em `gap-preview` đã có `TillGapEndpointsTest`. File này ghim
 * hành vi ĐANG ĐÚNG, không đổi nó.
 *
 * Bài quan trọng nhất là bài ÂM ở cuối: đơn của chi nhánh khác không được lọt
 * vào `orders`. Nó là thứ duy nhất ở đây bắt được nếu ai đó bỏ điều kiện
 * `branch_id` trong `EloquentBranchOrderReads::unresolvedForBranchBefore()` —
 * mọi bài 200/403 khác vẫn xanh nguyên khi rò dữ liệu.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    // Hai chi nhánh CÙNG tổ chức — phép kiểm cấp tổ chức một mình sẽ cho qua,
    // nên đây mới ghim đúng ranh giới chi nhánh.
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'unresolved-shop',
        'is_active' => true,
    ]);
    $this->otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'unresolved-other-shop',
        'is_active' => true,
    ]);

    // `resolveTillForBranch()` mặc định tìm till_code = MAIN.
    $this->till = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
    ]);
    $this->otherTill = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => $this->otherShop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
    ]);

    $this->cash = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);

    $role = Role::firstOrCreate(['slug' => 'shop-staff'], ['name' => 'Shop Staff', 'level' => 10]);

    // Thu ngân bị ghim vào ĐÚNG chi nhánh đang đo.
    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($role, $this->orgId, $this->shop->id);

    // Thu ngân bị ghim vào chi nhánh KHÁC, cùng tổ chức.
    $this->otherBranchCashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->otherBranchCashier->assignRole($role, $this->orgId, $this->otherShop->id);

    // `branch_id IS NULL` = all_branches_access = MỌI chi nhánh (KHÔNG phải
    // "không chi nhánh nào") — docs/explanation/branch-isolation.md.
    $this->orgWideUser = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->orgWideUser->assignRole($role, $this->orgId, null);

    // Người của tổ chức khác, cũng all_branches_access — nhưng ở org của họ.
    $this->foreignOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->foreignOrgId,
        'console_organization_id' => $this->foreignOrgId,
    ]);
    $this->outsider = User::factory()->create(['console_organization_id' => $this->foreignOrgId]);
    $this->outsider->assignRole($role, $this->foreignOrgId, null);
});

/*
 * Tên hàm phải DUY NHẤT trong cả suite: Pest nạp mọi file test vào cùng một tiến
 * trình, nên trùng tên với helper của `UnresolvedOrdersAtShiftOpenTest` /
 * `TillGapEndpointsTest` là "cannot redeclare function".
 */

/** Ca đã chốt — ranh ca mà endpoint dùng làm mốc cắt. */
function uoeSettledShift(Till $till, Carbon $endedAt): TillSession
{
    return TillSession::factory()->settled()->create([
        'till_id' => $till->id,
        'branch_id' => $till->branch_id,
        'brand_id' => $till->brand_id,
        'organization_id' => $till->organization_id,
        'default_currency_code' => 'JPY',
        'opened_at' => $endedAt->copy()->subHours(8),
        'closed_at' => $endedAt,
    ]);
}

/** Đơn còn treo tiền (`paying`/`checkout`), đóng dấu `created_at` thật. */
function uoeStuckOrder(
    Branch $branch,
    string $brandId,
    string $orgId,
    Carbon $createdAt,
    float $total,
    string $status = 'paying',
    ?string $tableId = null,
): CustomerOrder {
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-E'.random_int(100000, 999999),
        'order_type' => 'dine_in',
        'status' => $status,
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'branch_id' => $branch->id,
        'table_id' => $tableId,
        'subtotal' => $total,
        'total_amount' => $total,
        'opened_at' => $createdAt,
    ]);

    // `created_at` không nằm trong $fillable ⇒ Eloquent ghi giờ hiện tại và đơn
    // sẽ luôn nằm SAU ranh ca, tức mọi bài dưới đo nhầm ra 0.
    $order->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'table_id' => $tableId,
    ])->saveQuietly();

    return $order->refresh();
}

function uoePaySome(object $ctx, CustomerOrder $order, Branch $branch, float $amount): OrderPayment
{
    return OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $ctx->cash->id,
        'amount' => $amount,
        'refund_of_id' => null,
        'till_session_id' => null,
        'organization_id' => $ctx->orgId,
        'brand_id' => $ctx->brand->id,
        'branch_id' => $branch->id,
    ]);
}

/** Gọi endpoint bằng token SSO của `$user`, ngữ cảnh quán qua `X-Shop-Slug`. */
function uoeGet(User $user, string $slug)
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Shop-Slug', $slug)
        ->getJson('/api/v1/pos/till/unresolved-orders');
}

// ── 200 + hình dạng hợp đồng ────────────────────────────────────────────────

it('trả 200 với ĐỦ khoá hợp đồng cho thu ngân đúng chi nhánh', function () {
    $end = now()->subHours(3);
    $shift = uoeSettledShift($this->till, $end);

    // Hình dạng thật của ORD-2026-0217: tổng 4.720, đã thu 3.720, thiếu 1.000.
    $order = uoeStuckOrder($this->shop, $this->brand->id, $this->orgId, $end->copy()->subHour(), 4720.00);
    uoePaySome($this, $order, $this->shop, 3720.00);

    $response = uoeGet($this->cashier, $this->shop->slug)
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'previous_session',
                'currency_code',
                'orders',
                'totals' => ['count', 'outstanding_amount'],
            ],
        ]);

    $response
        ->assertJsonPath('data.currency_code', 'JPY')
        ->assertJsonPath('data.previous_session.id', $shift->id)
        ->assertJsonPath('data.previous_session.session_code', $shift->session_code)
        ->assertJsonPath('data.totals.count', 1)
        ->assertJsonPath('data.orders.0.id', $order->id)
        ->assertJsonPath('data.orders.0.order_code', $order->order_code)
        ->assertJsonPath('data.orders.0.status', 'paying')
        // Bàn đã nhả — dấu hiệu đơn mồ côi, thứ màn hình bàn không cho thấy.
        ->assertJsonPath('data.orders.0.table_released', true);

    // Tiền: ép kiểu rồi mới so. `json_encode` của một float chẵn rơi ra `1000`
    // hay `1000.0` tuỳ `serialize_precision`, so cứng sẽ đỏ theo môi trường chứ
    // không theo hành vi.
    expect((float) $response->json('data.totals.outstanding_amount'))->toBe(1000.0)
        ->and((float) $response->json('data.orders.0.total_amount'))->toBe(4720.0)
        ->and((float) $response->json('data.orders.0.paid_amount'))->toBe(3720.0)
        ->and((float) $response->json('data.orders.0.outstanding_amount'))->toBe(1000.0);
});

it('đọc được khi CHƯA có ca nào mở — đó chính là điểm của endpoint', function () {
    // Không dựng ca nào cả: màn mở ca gọi endpoint này TRƯỚC khi có ca.
    // Còn nguyên một đơn treo trong DB để chắc rằng 0 là do "chưa có ranh ca",
    // không phải do không có dữ liệu.
    uoeStuckOrder($this->shop, $this->brand->id, $this->orgId, now()->subDay(), 3000.00);

    expect(TillSession::query()->where('branch_id', $this->shop->id)->count())->toBe(0);

    uoeGet($this->cashier, $this->shop->slug)
        ->assertOk()
        ->assertJsonPath('data.previous_session', null)
        ->assertJsonPath('data.currency_code', 'JPY')
        ->assertJsonPath('data.orders', [])
        ->assertJsonPath('data.totals.count', 0);
});

it('người dùng all_branches_access (branch_id NULL) đọc được — NULL là MỌI chi nhánh', function () {
    $end = now()->subHours(3);
    uoeSettledShift($this->till, $end);
    uoeStuckOrder($this->shop, $this->brand->id, $this->orgId, $end->copy()->subHour(), 1500.00);

    $response = uoeGet($this->orgWideUser, $this->shop->slug)
        ->assertOk()
        ->assertJsonPath('data.totals.count', 1);

    expect((float) $response->json('data.totals.outstanding_amount'))->toBe(1500.0);
});

// ── Cách ly ─────────────────────────────────────────────────────────────────

it('403 cho thu ngân bị ghim vào chi nhánh KHÁC trong cùng tổ chức', function () {
    $end = now()->subHours(3);
    uoeSettledShift($this->till, $end);
    uoeStuckOrder($this->shop, $this->brand->id, $this->orgId, $end->copy()->subHour(), 900.00);

    uoeGet($this->otherBranchCashier, $this->shop->slug)
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this shop.');

    // Đối chứng dương: chính chi nhánh của người đó thì đọc được ⇒ 403 ở trên là
    // cổng chi nhánh, không phải setup hỏng.
    uoeGet($this->otherBranchCashier, $this->otherShop->slug)->assertOk();
});

it('403 cho người dùng của tổ chức KHÁC', function () {
    uoeSettledShift($this->till, now()->subHours(3));

    uoeGet($this->outsider, $this->shop->slug)
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this shop.');
});

it('401 khi không có token', function () {
    test()->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/till/unresolved-orders')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'TOKEN_REQUIRED');
});

it('device token của quán khác bị chặn BRANCH_MISMATCH', function () {
    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'pos',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
    ]);

    test()->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/pos/till/unresolved-orders')
        ->assertForbidden()
        ->assertJsonPath('code', 'BRANCH_MISMATCH');
});

it('device token đúng chi nhánh đọc được — viewCurrent nằm trong allowlist thiết bị', function () {
    $end = now()->subHours(3);
    uoeSettledShift($this->till, $end);
    uoeStuckOrder($this->shop, $this->brand->id, $this->orgId, $end->copy()->subHour(), 700.00, 'checkout');

    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'pos',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    test()->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/pos/till/unresolved-orders')
        ->assertOk()
        ->assertJsonPath('data.totals.count', 1);
});

// ── Bài ÂM: rò dữ liệu qua chi nhánh ────────────────────────────────────────

it('KHÔNG trả đơn treo của chi nhánh khác — bài duy nhất bắt được nếu bỏ điều kiện branch_id', function () {
    $end = now()->subHours(3);
    uoeSettledShift($this->till, $end);
    // Chi nhánh kia cũng có ranh ca riêng, để đơn của nó đủ điều kiện về THỜI
    // GIAN — nếu nó không hiện thì chỉ có thể vì bộ lọc chi nhánh.
    uoeSettledShift($this->otherTill, $end);

    $mine = uoeStuckOrder($this->shop, $this->brand->id, $this->orgId, $end->copy()->subHour(), 1200.00);
    $theirs = uoeStuckOrder($this->otherShop, $this->brand->id, $this->orgId, $end->copy()->subHour(), 9900.00, 'checkout');

    $response = uoeGet($this->cashier, $this->shop->slug)->assertOk();

    $codes = collect($response->json('data.orders'))->pluck('order_code')->all();

    expect($codes)->toBe([$mine->order_code])
        ->and($codes)->not->toContain($theirs->order_code);

    // Tiền của quán kia cũng không được cộng vào tổng.
    $response->assertJsonPath('data.totals.count', 1);
    expect((float) $response->json('data.totals.outstanding_amount'))->toBe(1200.0);
});

// ── #2745 vòng 2 · đường ĐỌC này cũng không được đẻ ra két ──────────────────

/** Như `uoeGet` nhưng gắn `?till_code=` do caller khai. */
function uoeGetWithTillCode(User $user, string $slug, string $tillCode)
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Shop-Slug', $slug)
        ->getJson('/api/v1/pos/till/unresolved-orders?till_code='.urlencode($tillCode));
}

it('till_code bịa: 200 rỗng, và KHÔNG tạo hàng tills nào', function () {
    $before = Till::query()->count();

    uoeGetWithTillCode($this->cashier, $this->shop->slug, 'KHONG-CO-THAT')
        ->assertOk()
        ->assertJsonPath('data.previous_session', null)
        ->assertJsonPath('data.orders', [])
        ->assertJsonPath('data.totals.count', 0);

    // Vòng 1 của #2745 vá `gap-preview` rồi khai nó là đường DUY NHẤT caller
    // dựng được két. Review probe ra cửa này còn mở: route đi
    // `shiftBoundaryTillForBranch()`, nhánh caller-supplied cũng `firstOrCreate`.
    expect(Till::query()->count())->toBe($before)
        ->and(Till::query()->where('till_code', 'KHONG-CO-THAT')->exists())->toBeFalse();
});

it('chi nhánh CHƯA có két: mã bịa không được thành két đo ranh ca', function () {
    // Ca nguy hiểm nhất, và là lý do cửa này nặng hơn gap-preview: hàng rác trở
    // thành két DUY NHẤT của chi nhánh, nên lượt quét sau (không truyền
    // `till_code`) rơi vào shortcut `count === 1` và lấy chính nó làm mốc ranh
    // ca cho cả chi nhánh.
    $fresh = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'chua-co-ket',
        'is_active' => true,
    ]);
    expect(Till::query()->where('branch_id', $fresh->id)->count())->toBe(0);

    // `cashier` bị ghim vào `$this->shop` nên chi nhánh mới trả 403 —
    // `orgWideUser` (`branch_id IS NULL` = MỌI chi nhánh) mới đo được ca này.
    uoeGetWithTillCode($this->orgWideUser, $fresh->slug, 'RAC-PROBE')->assertOk();

    expect(Till::query()->where('branch_id', $fresh->id)->count())->toBe(0);
});

it('till_code THẬT vẫn quét đúng két đó — bản vá không bịt đường đúng', function () {
    $sub = Till::factory()->create([
        'till_code' => 'SUB',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
    ]);
    $end = now()->subHours(3);
    uoeSettledShift($sub, $end);
    uoeStuckOrder($this->shop, (string) $this->brand->id, $this->orgId,
        $end->copy()->subHour(), 900.00);

    uoeGetWithTillCode($this->cashier, $this->shop->slug, 'SUB')
        ->assertOk()
        ->assertJsonPath('data.totals.count', 1);
});
