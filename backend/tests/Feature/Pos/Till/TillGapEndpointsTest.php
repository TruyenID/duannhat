<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Plan-044 R2 — HTTP surface for the two read endpoints:
 *   GET /pos/till/gap-preview
 *   GET /pos/till/sessions/{id}/order-summary
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'gap-shop',
        'is_active' => true,
    ]);
    $role = Role::firstOrCreate(['slug' => 'shop-staff'], ['name' => 'Shop Staff', 'level' => 10]);
    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($role, $this->orgId);
    $this->cashier->refresh();

    $this->till = Till::firstOrCreate(
        ['branch_id' => $this->shop->id, 'till_code' => 'MAIN'],
        ['default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0, 'is_active' => true, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId],
    );
    $this->cash = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
});

function gapOrder(object $ctx, string $status): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-G'.random_int(1000, 9999),
        'order_type' => 'dine_in', 'status' => $status,
        'subtotal' => 1000, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 1000, 'paid_amount' => 0, 'total_tip' => 0, 'opened_at' => now(),
        'branch_id' => $ctx->shop->id, 'brand_id' => $ctx->brand->id, 'organization_id' => $ctx->orgId,
    ]);
}

function asCashier(object $ctx)
{
    Sanctum::actingAs($ctx->cashier);

    return $ctx->withHeader('X-Shop-Slug', $ctx->shop->slug);
}

it('GET gap-preview returns gap payments after the previous shift, tagged is_cash', function () {
    TillSession::create([
        'session_code' => 'SHIFT-PREV', 'status' => 'settled', 'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subHours(4), 'closed_at' => now()->subHours(2),
        'till_id' => $this->till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    $order = gapOrder($this, 'paying');
    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id, 'payment_method_id' => $this->cash->id, 'amount' => 800,
        'refund_of_id' => null, 'till_session_id' => null, 'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'created_at' => now()->subMinutes(30),
    ]);

    asCashier($this)->getJson('/api/v1/pos/till/gap-preview')
        ->assertOk()
        ->assertJsonPath('data.totals.count', 1)
        ->assertJsonPath('data.payments.0.is_cash', true)
        ->assertJsonPath('data.payments.0.amount', 800);
});

it('GET gap-preview is empty when there is no prior terminal session', function () {
    asCashier($this)->getJson('/api/v1/pos/till/gap-preview')
        ->assertOk()
        ->assertJsonPath('data.previous_session', null)
        ->assertJsonPath('data.totals.count', 0);
});

it('GET gap-preview requires authentication', function () {
    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/till/gap-preview')
        ->assertUnauthorized();
});

it('GET order-summary returns paid + unpaid-carry counts', function () {
    $session = TillSession::create([
        'session_code' => 'SHIFT-OS', 'status' => 'open', 'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0, 'opened_at' => now()->subHour(),
        'till_id' => $this->till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);

    $paidOrder = gapOrder($this, 'paying');
    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $paidOrder->id, 'payment_method_id' => $this->cash->id, 'amount' => 1000,
        'refund_of_id' => null, 'till_session_id' => $session->id, 'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
    ]);
    gapOrder($this, 'open'); // unpaid active → carries

    asCashier($this)->getJson("/api/v1/pos/till/sessions/{$session->id}/order-summary")
        ->assertOk()
        ->assertJsonPath('data.paid_orders_count', 1)
        ->assertJsonPath('data.unpaid_carry_count', 1);
});

/*
 * #2744 — hoàn MỘT PHẦN không phải hoàn hết.
 *
 * Sổ hoàn tiền của repo: hàng gốc GIỮ +X và flip status sang `refunded`, khoản
 * hoàn là hàng RIÊNG mang `refund_of_id` và số ÂM. Vậy `refunded` chỉ nói "đã
 * có hoàn", KHÔNG nói "hết tiền". Vị ngữ cũ lọc `status = succeeded` nên khoản
 * 5000 hoàn 1000 rơi khỏi CẢ preview LẪN claim, và 4000 thật trong ngăn kéo
 * không bao giờ gắn vào ca nào — chỉ lộ ra thành variance lúc đóng ca.
 */
it('gap-preview giữ khoản hoàn MỘT PHẦN và hiện NET, không phải số gộp', function () {
    TillSession::create([
        'session_code' => 'SHIFT-PREV', 'status' => 'settled', 'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subHours(4), 'closed_at' => now()->subHours(2),
        'till_id' => $this->till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    $order = gapOrder($this, 'paying');

    // Gốc 5000 trong cửa sổ gap, ĐÃ flip `refunded` vì có hoàn một phần.
    $origin = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id, 'payment_method_id' => $this->cash->id, 'amount' => 5000,
        'status' => 'refunded',
        'refund_of_id' => null, 'till_session_id' => null, 'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'created_at' => now()->subMinutes(30),
    ]);
    // Hàng hoàn −1000, trỏ về gốc.
    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id, 'payment_method_id' => $this->cash->id, 'amount' => -1000,
        'refund_of_id' => $origin->id, 'till_session_id' => null, 'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'created_at' => now()->subMinutes(20),
    ]);

    asCashier($this)->getJson('/api/v1/pos/till/gap-preview')
        ->assertOk()
        // Khoản PHẢI có mặt — đây là bug gốc.
        ->assertJsonPath('data.totals.count', 1)
        ->assertJsonPath('data.payments.0.id', $origin->id)
        // …và hiện NET 4000, vì đó là số tiền THẬT còn trong ngăn kéo.
        ->assertJsonPath('data.payments.0.amount', 4000)
        ->assertJsonPath('data.payments.0.gross_amount', 5000)
        ->assertJsonPath('data.totals.cash_amount', 4000);
});

/*
 * #2744 — CONTROL NGƯỢC: hoàn HẾT thì két không giữ gì, phải biến mất.
 *
 * Không có bài này thì một "bản sửa" bỏ luôn bộ lọc status cũng xanh, và ta sẽ
 * mời thu ngân gán một khoản đã trả lại khách trọn vẹn.
 */
it('gap-preview LOẠI khoản đã hoàn HẾT — net 0 thì không còn gì để gán', function () {
    TillSession::create([
        'session_code' => 'SHIFT-PREV', 'status' => 'settled', 'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subHours(4), 'closed_at' => now()->subHours(2),
        'till_id' => $this->till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    $order = gapOrder($this, 'paying');
    $origin = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id, 'payment_method_id' => $this->cash->id, 'amount' => 3000,
        'status' => 'refunded',
        'refund_of_id' => null, 'till_session_id' => null, 'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'created_at' => now()->subMinutes(30),
    ]);
    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id, 'payment_method_id' => $this->cash->id, 'amount' => -3000,
        'refund_of_id' => $origin->id, 'till_session_id' => null, 'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'created_at' => now()->subMinutes(20),
    ]);

    asCashier($this)->getJson('/api/v1/pos/till/gap-preview')
        ->assertOk()
        ->assertJsonPath('data.totals.count', 0)
        ->assertJsonPath('data.totals.cash_amount', 0);
});

/*
 * #2744 vòng 2 — hoàn ĐÃ gắn ca khác thì KHÔNG trừ khỏi preview.
 *
 * `reconcile()` (#523) đã trừ −Y vào ngăn kéo của ca mà hàng hoàn thuộc về.
 * Trừ lần nữa ở đây là đếm hai lần: preview báo 700 trong khi tiền giữ riêng
 * thật sự là 1.000, và thu ngân đối soát với một con số không tồn tại.
 *
 * Bài này ra đời vì kiểm đột biến: gỡ điều kiện `r.till_session_id IS NULL` mà
 * cả 22 bài vẫn xanh — tức điều kiện ấy chưa được bài nào canh.
 */
it('gap-preview KHÔNG trừ khoản hoàn đã thuộc ca khác — tiền giữ riêng là GROSS', function () {
    $prev = TillSession::create([
        'session_code' => 'SHIFT-PREV', 'status' => 'settled', 'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0,
        'opened_at' => now()->subHours(4), 'closed_at' => now()->subHours(2),
        'till_id' => $this->till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    $order = gapOrder($this, 'paying');

    $origin = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id, 'payment_method_id' => $this->cash->id, 'amount' => 1000,
        'status' => 'refunded',
        'refund_of_id' => null, 'till_session_id' => null, 'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'created_at' => now()->subMinutes(30),
    ]);
    // Hàng hoàn ĐÃ gắn ca trước ⇒ ca đó đã gánh −300.
    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id, 'payment_method_id' => $this->cash->id, 'amount' => -300,
        'refund_of_id' => $origin->id, 'till_session_id' => $prev->id, 'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'created_at' => now()->subMinutes(20),
    ]);

    asCashier($this)->getJson('/api/v1/pos/till/gap-preview')
        ->assertOk()
        ->assertJsonPath('data.totals.count', 1)
        ->assertJsonPath('data.payments.0.amount', 1000)
        ->assertJsonPath('data.totals.cash_amount', 1000);
});

// ── #2745 · đường ĐỌC không được đẻ ra két ──────────────────────────────────

it('GET gap-preview với till_code bịa: 200 rỗng, và KHÔNG tạo hàng tills nào', function () {
    $before = Till::query()->count();

    asCashier($this)->getJson('/api/v1/pos/till/gap-preview?till_code=KHONG-CO-THAT')
        ->assertOk()
        ->assertJsonPath('data.previous_session', null)
        ->assertJsonPath('data.payments', [])
        ->assertJsonPath('data.totals.count', 0);

    // Đây MỚI là bài. Assert "200 rỗng" một mình vẫn xanh khi `firstOrCreate`
    // vừa dựng một két rác — hàng mới không có ca nào trước đó nên preview
    // cũng rỗng. Phải đếm bảng.
    expect(Till::query()->count())->toBe($before)
        ->and(Till::query()->where('till_code', 'KHONG-CO-THAT')->exists())->toBeFalse();
});

it('két rác không tồn tại được thì không thể bẻ ranh ca — 20 mã bịa, bảng đứng yên', function () {
    $before = Till::query()->count();

    // #2724 vá "ranh ca đo trên két có ca kết thúc gần nhất". Cửa đó chỉ nguy
    // hiểm khi có két lạ trong bảng. Bơm nhiều mã để một lần rò cũng lộ ra.
    //
    // Vòng 1 của #2745 viết ở đây rằng gap-preview là "đường DUY NHẤT caller tự
    // dựng được két". SAI, và review đã probe ra: `GET
    // /pos/till/unresolved-orders?till_code=` cũng tạo — nó đi
    // `shiftBoundaryTillForBranch()`, nhánh caller-supplied cũng
    // `firstOrCreate`. Vòng 2 vá nốt cửa đó; bài canh nó nằm ở
    // `UnresolvedOrdersEndpointTest`, cạnh route của chính nó.
    foreach (range(1, 20) as $i) {
        asCashier($this)->getJson('/api/v1/pos/till/gap-preview?till_code=RAC-'.$i)
            ->assertOk();
    }

    expect(Till::query()->count())->toBe($before);
});

it('till_code THẬT vẫn chạy như cũ — bản vá không bịt luôn đường đúng', function () {
    $sub = Till::factory()->create([
        'till_code' => 'SUB',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
    ]);
    TillSession::factory()->settled()->create([
        'till_id' => $sub->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'opened_at' => now()->subHours(9),
        'closed_at' => now()->subHours(2),
    ]);

    $order = gapOrder($this, 'closed');
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
        'payment_method_id' => $this->cash->id,
        'amount' => 1000,
        'status' => 'succeeded',
        'refund_of_id' => null,
        'till_session_id' => null,
    ]);

    asCashier($this)->getJson('/api/v1/pos/till/gap-preview?till_code=SUB')
        ->assertOk()
        ->assertJsonPath('data.totals.count', 1);
});
