<?php

declare(strict_types=1);

/**
 * T3 của #2876 (#2880) — tra cứu giao dịch toàn kênh.
 *
 * Bộ này ghim ba thứ:
 *
 *   1. **検索要件 của 電子帳簿保存法** — tra được theo 取引年月日 · 取引金額 ·
 *      取引先, và KẾT HỢP được từ hai trục trở lên. Đây là nghĩa vụ pháp lý,
 *      không phải tiện ích, nên nó có test riêng chứ không nấp trong một bài
 *      "happy path".
 *   2. **Một ô `reference` cho mọi loại mã** — người vận hành cầm mã nào cũng
 *      ra, kể cả mã Glory trần (không có tiền tố `glory:`).
 *   3. **Không rò chéo brand.** Với API tiền, đây là bài quan trọng hơn cả hai
 *      bài trên.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentAttempt;
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
        'slug' => 'mine-'.Str::lower(Str::random(4)),
        'is_active' => true,
    ]);
    $this->otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'other-'.Str::lower(Str::random(4)),
        'is_active' => true,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $this->base = "/api/v1/hq/{$this->brand->slug}/transactions";

    $this->pay = fn (array $over = []) => OrderPayment::factory()->create(array_merge([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 1000,
        'paid_at' => now(),
    ], $over));
});

it('#2880 tra được theo 取引金額 — KHOẢNG tiền, không phải giá trị chính xác', function () {
    ($this->pay)(['amount' => 500, 'reference_no' => 'small']);
    ($this->pay)(['amount' => 3000, 'reference_no' => 'mid']);
    ($this->pay)(['amount' => 90000, 'reference_no' => 'big']);

    $refs = array_column(
        $this->getJson($this->base.'?amount_min=1000&amount_max=5000')->assertOk()->json('data'),
        'reference_no'
    );

    expect($refs)->toBe(['mid']);
});

it('#2880 KẾT HỢP hai trục — tiền + đối tác — đúng yêu cầu 検索要件', function () {
    ($this->pay)(['amount' => 3000, 'reference_no' => 'stripe-mid', 'gateway_provider_snapshot' => 'stripe']);
    ($this->pay)(['amount' => 3000, 'reference_no' => 'paypay-mid', 'gateway_provider_snapshot' => 'paypay']);
    ($this->pay)(['amount' => 90000, 'reference_no' => 'stripe-big', 'gateway_provider_snapshot' => 'stripe']);

    $refs = array_column(
        $this->getJson($this->base.'?amount_min=1000&amount_max=5000&provider=stripe')->assertOk()->json('data'),
        'reference_no'
    );

    expect($refs)->toBe(['stripe-mid']);
});

it('#2880 MỘT ô reference ra đúng hàng — dù mã thuộc cột nào', function () {
    $attempt = PaymentAttempt::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'provider_object_id' => 'pi_stripe_123',
    ]);

    ($this->pay)(['reference_no' => 'ref-A']);
    ($this->pay)(['reference_no' => 'ref-B', 'payment_attempt_id' => $attempt->id]);
    // Máy trạm đóng khoá dạng `glory:<mã>`; người vận hành cầm mã TRẦN.
    ($this->pay)(['reference_no' => 'ref-C', 'idempotency_key' => 'glory:T-777']);

    $find = fn (string $ref) => array_column(
        $this->getJson($this->base.'?reference='.urlencode($ref))->assertOk()->json('data'),
        'reference_no'
    );

    expect($find('ref-A'))->toBe(['ref-A'])
        // Mã intent của cổng — nằm ở `payment_attempts`, không ở `order_payments`.
        ->and($find('pi_stripe_123'))->toBe(['ref-B'])
        // Mã Glory TRẦN: người tra không phải biết tiền tố nội bộ.
        ->and($find('T-777'))->toBe(['ref-C']);
});

it('#2880 KHÔNG rò giao dịch của brand khác', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->otherBrand->console_brand_id,
    ]);

    ($this->pay)(['reference_no' => 'mine']);
    ($this->pay)([
        'brand_id' => $this->otherBrand->id,
        'branch_id' => $otherBranch->id,
        'reference_no' => 'theirs',
    ]);

    $refs = array_column($this->getJson($this->base)->assertOk()->json('data'), 'reference_no');

    expect($refs)->toBe(['mine']);
});

it('#2880 lọc branch_id của brand KHÁC không kéo được dữ liệu ra', function () {
    // Cái bẫy thật, giống hệt `HqSettlementApiTest`: nếu bộ lọc người gọi gửi
    // được áp THAY CHO phạm vi brand thay vì áp THÊM VÀO, một id đoán đúng sẽ
    // đọc được tiền của brand khác.
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->otherBrand->console_brand_id,
    ]);
    ($this->pay)([
        'brand_id' => $this->otherBrand->id,
        'branch_id' => $otherBranch->id,
        'reference_no' => 'theirs',
    ]);

    $this->getJson($this->base.'?branch_id='.$otherBranch->id)
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('#2911 branch_id là BỘ LỌC, không phải rào — thấy MỌI chi nhánh của brand', function () {
    // Ghim luật, không ghim hành vi tình cờ.
    //
    // Bản đầu của #2880 mang tham số `allowed_branch_ids` mà controller không
    // bao giờ truyền — một rào CÂM. Gỡ nó đi thì mã nói đúng thứ nó làm, nhưng
    // "đúng thứ nó làm" cần được viết ra, nếu không người sau sẽ đọc màn hình
    // này và tưởng có phân quyền theo chi nhánh.
    //
    // Phạm vi HQ là ORGANIZATION + BRAND. Muốn đổi thành per-branch thì đó là
    // quyết định cắt ngang mọi màn HQ đọc tiền — và bài này sẽ đỏ, đúng lúc,
    // buộc người đổi phải đối diện với nó thay vì lặng lẽ siết một màn.
    $branchB = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    ($this->pay)(['reference_no' => 'chi-nhanh-A']);
    ($this->pay)(['branch_id' => $branchB->id, 'reference_no' => 'chi-nhanh-B']);

    $refs = array_column($this->getJson($this->base)->assertOk()->json('data'), 'reference_no');

    sort($refs);

    expect($refs)->toBe(['chi-nhanh-A', 'chi-nhanh-B']);
});

it('#2880 payload là ALLOWLIST — không rò cột nội bộ', function () {
    ($this->pay)(['reference_no' => 'ref-A', 'note' => 'ghi chú nội bộ không được ra API']);

    $body = $this->getJson($this->base)->assertOk()->getContent();

    // `note` là cột có thật trên `order_payments` và KHÔNG nằm trong allowlist.
    // Nếu ai đó đổi `payload()` thành `toArray()`, bài này đỏ ngay.
    expect($body)->not->toContain('ghi chú nội bộ');
});

it('#2880 chỉ ĐỌC — không có route ghi nào dưới transactions', function () {
    $this->postJson($this->base, [])->assertStatus(405);
});
