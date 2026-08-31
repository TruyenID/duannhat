<?php

/**
 * #1370 (plan-050 M5 T5.0) — đường đọc tầng settlement.
 *
 * Backend đã sinh dữ liệu đối soát từ M1/M2/M4-core và scheduler chạy hằng
 * ngày, nhưng không có route nào đọc ra. Bộ test này ghim hai thứ mà một API
 * tiền KHÔNG được sai:
 *
 *   1. không rò dữ liệu chéo brand — kể cả khi người gọi tự gõ connection_id
 *      của brand khác vào query string;
 *   2. không bao giờ trả ESTIMATE (hợp đồng G1). `estimated_fee_minor` là con
 *      số phỏng đoán lúc bán; một dashboard kế toán đọc phải nó là dashboard
 *      nói dối, và cái sai đó không tự lộ vì con số vẫn trông hợp lý.
 */

use App\Models\Brand;
use App\Models\GatewayPayout;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentSettlement;
use App\Models\SettlementReportBatch;
use App\Models\User;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    // Dùng lại provider đã có: `code` là UNIQUE và migration/seed đã nạp sẵn
    // bộ provider, nên factory sẽ đụng ràng buộc chứ không phải tạo mới.
    $provider = PaymentGatewayProvider::query()->first()
        ?? PaymentGatewayProvider::factory()->create(['is_active' => true]);

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

    $this->connection = PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'owner_branch_id' => null,
    ]);

    $this->otherConnection = PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->otherBrand->id,
        'owner_branch_id' => null,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $this->base = "/api/v1/hq/{$this->brand->slug}/settlements";
});

it('lists only the settlement rows of the brand in the URL', function () {
    PaymentSettlement::factory()->create([
        'connection_id' => $this->connection->id,
        'external_ref' => 'txn_mine',
    ]);
    PaymentSettlement::factory()->create([
        'connection_id' => $this->otherConnection->id,
        'external_ref' => 'txn_theirs',
    ]);

    $response = $this->getJson($this->base)->assertOk();

    $refs = array_column($response->json('data'), 'external_ref');

    expect($refs)->toBe(['txn_mine']);
});

it('#2981 hides fee adjustments by default but allows an explicit audit filter', function () {
    PaymentSettlement::factory()->create([
        'connection_id' => $this->connection->id,
        'external_ref' => 'txn_sale',
        'status' => SettlementStatus::Reconciled,
    ]);
    PaymentSettlement::factory()->create([
        'connection_id' => $this->connection->id,
        'external_ref' => 'txn_jct_fee',
        'status' => SettlementStatus::FeeAdjustment,
    ]);

    $defaultRefs = array_column(
        $this->getJson($this->base)->assertOk()->json('data'),
        'external_ref',
    );
    $feeRefs = array_column(
        $this->getJson($this->base.'?status=fee_adjustment')->assertOk()->json('data'),
        'external_ref',
    );

    expect($defaultRefs)->toBe(['txn_sale'])
        ->and($feeRefs)->toBe(['txn_jct_fee']);
});

/**
 * Cái bẫy thật: bộ lọc `connection_id` do người gọi cung cấp. Nếu nó được áp
 * THAY CHO phạm vi brand thay vì áp THÊM VÀO, thì một id đoán đúng sẽ đọc được
 * tiền của tenant khác.
 */
it('returns nothing when asked for a connection that belongs to another brand', function () {
    PaymentSettlement::factory()->create([
        'connection_id' => $this->otherConnection->id,
        'external_ref' => 'txn_theirs',
    ]);

    $this->getJson($this->base.'?connection_id='.$this->otherConnection->id)
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('never exposes the L1 estimate column (G1)', function () {
    PaymentSettlement::factory()->create(['connection_id' => $this->connection->id]);

    $body = $this->getJson($this->base)->assertOk()->getContent();

    expect($body)->not->toContain('estimated_fee_minor')
        ->and($body)->not->toContain('estimate');
});

it('filters unmatched rows — the orphan shape, not a separate table', function () {
    PaymentSettlement::factory()->create([
        'connection_id' => $this->connection->id,
        'external_ref' => 'txn_orphan',
        'order_payment_id' => null,
    ]);

    $response = $this->getJson($this->base.'?unmatched=1')->assertOk();

    expect(array_column($response->json('data'), 'external_ref'))->toBe(['txn_orphan']);
});

it('lists import batches scoped to the brand', function () {
    SettlementReportBatch::factory()->create([
        'connection_id' => $this->connection->id,
        'cycle_label' => 'mine-2026-07',
    ]);
    SettlementReportBatch::factory()->create([
        'connection_id' => $this->otherConnection->id,
        'cycle_label' => 'theirs-2026-07',
    ]);

    $response = $this->getJson($this->base.'/batches')->assertOk();

    expect(array_column($response->json('data'), 'cycle_label'))->toBe(['mine-2026-07']);
});

it('lists gateway payouts scoped to the brand', function () {
    GatewayPayout::factory()->create([
        'connection_id' => $this->connection->id,
        'external_payout_id' => 'po_mine',
    ]);
    GatewayPayout::factory()->create([
        'connection_id' => $this->otherConnection->id,
        'external_payout_id' => 'po_theirs',
    ]);

    $response = $this->getJson($this->base.'/payouts')->assertOk();

    expect(array_column($response->json('data'), 'external_payout_id'))->toBe(['po_mine']);
});

/**
 * `SettlementAgingReportService::pendingPayoutAging(null)` quét MỌI connection
 * của MỌI tenant — nó không biết brand là gì. Controller phải luôn đưa danh
 * sách connection của brand vào, không bao giờ gọi với `null`.
 */
it('ages only the brand money, never the whole fleet', function () {
    PaymentSettlement::factory()->create([
        'connection_id' => $this->connection->id,
        'status' => SettlementStatus::PendingPayout,
        'net_minor' => 9_640,
        'provider_settled_at' => now()->subDays(2),
    ]);
    PaymentSettlement::factory()->create([
        'connection_id' => $this->otherConnection->id,
        'status' => SettlementStatus::PendingPayout,
        'net_minor' => 500_000,
        'provider_settled_at' => now()->subDays(2),
    ]);

    $rows = $this->getJson($this->base.'/aging')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['connection_id'])->toBe($this->connection->id)
        ->and($rows[0]['total_net_minor'])->toBe(9_640);
});

it('rejects a user with no access to the organization', function () {
    $outsider = User::factory()->create(['console_organization_id' => (string) Str::uuid()]);

    $this->actingAs($outsider)
        ->getJson($this->base)
        ->assertForbidden();
});

/*
 * plan-050 T5.2 — export CSV cho kế toán.
 */

it('#1155 T5.2 export CSV KHÔNG phân trang — file thiếu trông y hệt file đủ', function () {
    // Đây là bất biến quan trọng nhất của cả endpoint. Mọi endpoint khác trong
    // controller này đều phân trang; nếu export vô tình thừa hưởng điều đó, một
    // file kế toán bị cắt vẫn mở được, vẫn cộng ra một con số, và con số đó SAI
    // mà không có dấu hiệu gì.
    //
    // 60 dòng > mọi per_page mặc định trong repo này.
    PaymentSettlement::factory()->count(60)->create([
        'connection_id' => $this->connection->id,
        'currency' => 'JPY',
    ]);

    $csv = $this->get("{$this->base}/export")->assertOk()->streamedContent();

    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // 60 dòng dữ liệu + 1 dòng tiêu đề.
    expect($lines)->toHaveCount(61);
});

it('#1155 T5.2 chỉ xuất connection của brand đang xem', function () {
    PaymentSettlement::factory()->create([
        'connection_id' => $this->connection->id,
        'external_ref' => 'txn_cua_toi',
    ]);
    PaymentSettlement::factory()->create([
        'connection_id' => $this->otherConnection->id,
        'external_ref' => 'txn_cua_nguoi_khac',
    ]);

    $csv = $this->get("{$this->base}/export")->assertOk()->streamedContent();

    expect($csv)->toContain('txn_cua_toi')
        ->and($csv)->not->toContain('txn_cua_nguoi_khac');
});

it('#2981 CSV hides fee adjustments by default but exports them on explicit request', function () {
    PaymentSettlement::factory()->create([
        'connection_id' => $this->connection->id,
        'external_ref' => 'txn_sale_csv',
        'status' => SettlementStatus::Reconciled,
    ]);
    PaymentSettlement::factory()->create([
        'connection_id' => $this->connection->id,
        'external_ref' => 'txn_jct_fee_csv',
        'status' => SettlementStatus::FeeAdjustment,
    ]);

    $defaultCsv = $this->get("{$this->base}/export")->assertOk()->streamedContent();
    $feeCsv = $this->get("{$this->base}/export?status=fee_adjustment")->assertOk()->streamedContent();

    expect($defaultCsv)->toContain('txn_sale_csv')
        ->and($defaultCsv)->not->toContain('txn_jct_fee_csv')
        ->and($feeCsv)->toContain('txn_jct_fee_csv')
        ->and($feeCsv)->not->toContain('txn_sale_csv');
});

it('#1155 T5.2 tiền là SỐ NGUYÊN đơn vị nhỏ nhất, kèm cột currency', function () {
    // Chia cho 100 ở đây là đưa số thập phân vào một file mà Excel sẽ diễn giải
    // lại theo locale của máy — mà JPY không có phần lẻ còn VND lại khác. Người
    // nhận cần con số nguyên bản để tự đối chiếu với sao kê gateway.
    PaymentSettlement::factory()->create([
        'connection_id' => $this->connection->id,
        'currency' => 'JPY',
        'gross_minor' => 11000,
        'fee_minor' => 330,
        'fee_tax_minor' => 33,
        'net_minor' => 10637,
    ]);

    $csv = $this->get("{$this->base}/export")->assertOk()->streamedContent();

    expect($csv)->toContain('11000')
        ->and($csv)->toContain('10637')
        ->and($csv)->toContain('JPY')
        // Không có bản đã chia.
        ->and($csv)->not->toContain('110.00')
        ->and($csv)->not->toContain('106.37');
});

it('#1155 T5.2 có BOM UTF-8 để Excel không đọc ra mojibake', function () {
    PaymentSettlement::factory()->create(['connection_id' => $this->connection->id]);

    $csv = $this->get("{$this->base}/export")->assertOk()->streamedContent();

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");
});

it('#1155 T5.2 lọc theo cùng bộ tham số như trang danh sách', function () {
    PaymentSettlement::factory()->create([
        'connection_id' => $this->connection->id,
        'external_ref' => 'txn_khop',
        'order_payment_id' => null,
    ]);

    $csv = $this->get("{$this->base}/export?unmatched=1")->assertOk()->streamedContent();

    expect($csv)->toContain('txn_khop');
});

it('#1157 export lô báo cáo và payout cũng KHÔNG phân trang', function () {
    // Ba tab được hậu thuẫn bởi endpoint CÓ phân trang (settlements, batches,
    // payouts) đều mang cùng một lỗi kế toán nếu export chỉ lấy trang hiện tại.
    // Tab aging thì không — nó là bảng tổng hợp, trả về trọn vẹn.
    SettlementReportBatch::factory()->count(45)->create([
        'connection_id' => $this->connection->id,
    ]);
    GatewayPayout::factory()->count(45)->create([
        'connection_id' => $this->connection->id,
    ]);

    $batches = $this->get("{$this->base}/batches/export")->assertOk()->streamedContent();
    $payouts = $this->get("{$this->base}/payouts/export")->assertOk()->streamedContent();

    expect(array_filter(explode("\n", trim($batches))))->toHaveCount(46)
        ->and(array_filter(explode("\n", trim($payouts))))->toHaveCount(46)
        // Cùng một streamer nên cùng có BOM — đó là lý do gom vào một chỗ.
        ->and(substr($batches, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and(substr($payouts, 0, 3))->toBe("\xEF\xBB\xBF");
});
