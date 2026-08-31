<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\CashDeviceErrorEvent;
use App\Models\CashDeviceInventorySnapshot;
use App\Models\CashDeviceTransaction;
use App\Models\Device;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Models\Till;
use App\Models\TillSession;
use App\Services\Till\CashDrawerReconciliationService;
use Illuminate\Support\Str;

/*
 * T2 (#2879) + T5 (#2882) của #2876.
 *
 * Mọi bài đi QUA ENDPOINT cho phần nhận (#2622), và gọi thẳng service cho phần
 * đối soát (nó không có endpoint — nó là phép đọc).
 *
 * Nửa "PHẢI IM" ở đây nặng hơn nửa "PHẢI KÊU", và đó là chủ ý: một rào tiền
 * kêu oan sẽ bị tắt, và lúc đó nó không còn canh gì nữa.
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

    $this->glory = PeripheralDevice::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'type' => 'coin_changer',
        'is_active' => true,
    ]);

    $till = Till::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->session = TillSession::factory()->create([
        'till_id' => $till->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->postWs = fn (string $path, array $body) => $this
        ->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/'.$path, $body);

    $this->snapshot = fn (string $phase, array $over = []) => array_merge([
        'peripheral_device_id' => (string) $this->glory->id,
        'till_session_id' => (string) $this->session->id,
        'count_phase' => $phase,
        'denominations' => ['10000' => 2, '1000' => 5, '100' => 10],
        'bill_reject_count' => 0,
        'captured_at' => '2026-08-15T00:00:00Z',
    ], $over);
});

// ─────────────────────────────────────────────────────────────────────────────
// T2 — 在高 vào Cloud
// ─────────────────────────────────────────────────────────────────────────────

it('#2879 Cloud TỰ CỘNG total_minor, không nhận từ thiết bị', function () {
    // 10000×2 + 1000×5 + 100×10 = 26.000
    ($this->postWs)('cash-device-inventory', ['snapshots' => [
        ($this->snapshot)('opening', ['total_minor' => 999999]),
    ]])->assertStatus(202);

    expect((int) CashDeviceInventorySnapshot::query()->sole()->total_minor)->toBe(26000);
});

it('#2879 mệnh giá 在高不確定 bị LOẠI khỏi tổng, không âm thầm cộng vào', function () {
    ($this->postWs)('cash-device-inventory', ['snapshots' => [
        ($this->snapshot)('opening', ['uncertain_denominations' => ['10000']]),
    ]])->assertStatus(202);

    $row = CashDeviceInventorySnapshot::query()->sole();

    // 26.000 − (10000×2) = 6.000
    expect((int) $row->total_minor)->toBe(6000)
        ->and($row->uncertain_denominations)->toBe(['10000']);
});

it('#2879 mệnh giá bất định gửi dạng SỐ vẫn được loại — không im lặng mất cờ', function () {
    // Bản đầu dùng `array_filter(..., 'is_string')`, nên một client gửi số sẽ
    // bị vứt SẠCH cờ bất định — im lặng — và mọi mệnh giá máy không chắc lại
    // được đem cộng vào tổng. Đúng cái sai mà cột này sinh ra để chặn.
    ($this->postWs)('cash-device-inventory', ['snapshots' => [
        ($this->snapshot)('opening', ['uncertain_denominations' => [10000]]),
    ]])->assertStatus(202);

    expect((int) CashDeviceInventorySnapshot::query()->sole()->total_minor)->toBe(6000);
});

it('#2879 chụp lại cùng mốc là GHI ĐÈ, không nhân đôi', function () {
    ($this->postWs)('cash-device-inventory', ['snapshots' => [($this->snapshot)('opening')]])->assertStatus(202);
    $res = ($this->postWs)('cash-device-inventory', ['snapshots' => [
        ($this->snapshot)('opening', ['denominations' => ['1000' => 1]]),
    ]])->assertStatus(202);

    expect($res->json('updated'))->toBe(1)
        ->and(CashDeviceInventorySnapshot::query()->count())->toBe(1)
        ->and((int) CashDeviceInventorySnapshot::query()->sole()->total_minor)->toBe(1000);
});

// ─────────────────────────────────────────────────────────────────────────────
// T2 — đối soát BA CHÂN
// ─────────────────────────────────────────────────────────────────────────────

/** Dựng một ca đã có đủ hai ảnh chụp + lượt thu, rồi trả phán đoán. */
function reconcileWith(object $t, int $openMinor, int $closeMinor, int $deposited, int $dispensed, ?float $counted, ?float $expected): array
{
    CashDeviceInventorySnapshot::factory()->create([
        'organization_id' => $t->orgId, 'branch_id' => $t->branch->id,
        'peripheral_device_id' => $t->glory->id, 'till_session_id' => $t->session->id,
        'count_phase' => 'opening', 'denominations' => [], 'total_minor' => $openMinor,
        'uncertain_denominations' => null, 'captured_at' => now(),
    ]);
    CashDeviceInventorySnapshot::factory()->create([
        'organization_id' => $t->orgId, 'branch_id' => $t->branch->id,
        'peripheral_device_id' => $t->glory->id, 'till_session_id' => $t->session->id,
        'count_phase' => 'closing', 'denominations' => [], 'total_minor' => $closeMinor,
        'uncertain_denominations' => null, 'captured_at' => now(),
    ]);
    CashDeviceTransaction::factory()->create([
        'organization_id' => $t->orgId, 'branch_id' => $t->branch->id,
        'peripheral_device_id' => $t->glory->id, 'till_session_id' => $t->session->id,
        'glory_transaction_id' => 'T-'.Str::random(6), 'outcome' => 'finish',
        'deposited_minor' => $deposited, 'dispensed_minor' => $dispensed,
    ]);

    $t->session->forceFill([
        'counted_cash_amount' => $counted,
        'expected_cash_amount' => $expected,
    ])->save();

    return app(CashDrawerReconciliationService::class)->reconcile($t->session->fresh());
}

it('#2879 máy KHỚP + người LỆCH ⇒ người đếm sai, tiền vẫn trong máy', function () {
    // máy: 10.000 + (5.000 − 1.000) = 14.000, đóng đúng 14.000 ⇒ lệch máy 0.
    $out = reconcileWith($this, 10000, 14000, 5000, 1000, 13000.0, 14000.0);

    expect($out['verdict'])->toBe('human_count_error')
        ->and($out['machine_variance_minor'])->toBe(0)
        ->and($out['human_variance_minor'])->toBe(-1000);
});

it('#2879 máy LỆCH + người KHỚP ⇒ tiền ra khỏi máy NGOÀI SỔ — ô nặng nhất', function () {
    $out = reconcileWith($this, 10000, 13000, 5000, 1000, 14000.0, 14000.0);

    expect($out['verdict'])->toBe('cash_left_machine_off_book')
        ->and($out['machine_variance_minor'])->toBe(-1000)
        ->and($out['human_variance_minor'])->toBe(0);
});

it('#2879 cả hai LỆCH cùng chiều ⇒ tiền thật sự thiếu', function () {
    $out = reconcileWith($this, 10000, 13000, 5000, 1000, 13000.0, 14000.0);

    expect($out['verdict'])->toBe('cash_missing');
});

it('#2879 ca KHỚP ⇒ IM — verdict ok, không cảnh báo gì', function () {
    $out = reconcileWith($this, 10000, 14000, 5000, 1000, 14000.0, 14000.0);

    expect($out['verdict'])->toBe('ok')
        ->and($out['machine_variance_minor'])->toBe(0)
        ->and($out['human_variance_minor'])->toBe(0);
});

it('#2879 máy MẤT KẾT NỐI lúc chốt ca ⇒ KHÔNG kết luận, và vế người vẫn còn', function () {
    // Không có ảnh chụp nào. Đây là ca thật: quán VẪN phải chốt được ca.
    $this->session->forceFill([
        'counted_cash_amount' => 13000.0,
        'expected_cash_amount' => 14000.0,
    ])->save();

    $out = app(CashDrawerReconciliationService::class)->reconcile($this->session->fresh());

    expect($out['status'])->toBe('undetermined')
        ->and($out['verdict'])->toBe('undetermined')
        ->and($out['machine_variance_minor'])->toBeNull()
        // Mất chân máy KHÔNG được làm mất luôn phép đo vốn đã có.
        ->and($out['human_variance_minor'])->toBe(-1000);
});

it('#2879 mệnh giá 在高不確定 ⇒ KHÔNG kết luận, và NÓI RÕ đã loại cái nào', function () {
    CashDeviceInventorySnapshot::factory()->create([
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
        'peripheral_device_id' => $this->glory->id, 'till_session_id' => $this->session->id,
        'count_phase' => 'opening', 'denominations' => [], 'total_minor' => 10000,
        'uncertain_denominations' => ['5000'], 'captured_at' => now(),
    ]);
    CashDeviceInventorySnapshot::factory()->create([
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
        'peripheral_device_id' => $this->glory->id, 'till_session_id' => $this->session->id,
        'count_phase' => 'closing', 'denominations' => [], 'total_minor' => 14000,
        'uncertain_denominations' => null, 'captured_at' => now(),
    ]);

    $out = app(CashDrawerReconciliationService::class)->reconcile($this->session->fresh());

    expect($out['status'])->toBe('undetermined')
        // Im lặng loại là giấu mất một phần sự thật.
        ->and($out['excluded_denominations'])->toBe(['5000'])
        ->and($out['reason'])->toContain('在高不確定');
});

it('#2879 ngưỡng theo BRAND được tôn trọng — không hardcode một con số cho mọi quán', function () {
    // Brand đặt ngưỡng rộng: lệch 1.000 ¥ phải IM.
    //
    // Đây là bài học `SettlementAlertService`: một ngưỡng chung sẽ hoặc câm với
    // quán này hoặc la hét với quán kia, và ngưỡng sai chiều nào cũng giết cảnh
    // báo — quá chặt thì người ta tắt, quá lỏng thì nó không bắt được gì.
    BrandOrderPolicy::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cash_variance_tolerance_minor' => 5000,
    ]);

    $out = reconcileWith($this, 10000, 14000, 5000, 1000, 13000.0, 14000.0);

    expect($out['verdict'])->toBe('ok')
        // Con số lệch VẪN được trả về — ngưỡng quyết định có KÊU hay không,
        // không quyết định có ĐO hay không.
        ->and($out['human_variance_minor'])->toBe(-1000);
});

it('#2879 brand chưa cấu hình ⇒ dùng mặc định, hành vi không đổi', function () {
    $out = reconcileWith($this, 10000, 14000, 5000, 1000, 13000.0, 14000.0);

    expect($out['verdict'])->toBe('human_count_error');
});

// ─────────────────────────────────────────────────────────────────────────────
// T5 — sự cố có dấu thời gian
// ─────────────────────────────────────────────────────────────────────────────

it('#2882 ghi sự cố KHÔNG gắn giao dịch — lý do bảng này tồn tại', function () {
    ($this->postWs)('cash-device-errors', ['events' => [[
        'peripheral_device_id' => (string) $this->glory->id,
        // IP máy trạm không nằm trong allowlist của adapter: CẤU HÌNH SAI, và
        // nó xảy ra khi KHÔNG có lượt thu nào đang chạy.
        'error_title' => 'forbidden',
        'error_group' => 'forbidden',
        'occurred_at' => '2026-08-15T02:00:00Z',
    ]]])->assertStatus(202);

    $row = CashDeviceErrorEvent::query()->sole();

    expect($row->error_group)->toBe('forbidden')
        ->and($row->cash_device_transaction_id)->toBeNull()
        ->and($row->cleared_at)->toBeNull();
});

it('#2882 một sự cố = MỘT hàng, dù đẩy lại nhiều lần', function () {
    $event = [
        'peripheral_device_id' => (string) $this->glory->id,
        'error_title' => 'empty',
        'error_group' => 'change_shortage',
        'occurred_at' => '2026-08-15T03:00:00Z',
    ];

    ($this->postWs)('cash-device-errors', ['events' => [$event]])->assertStatus(202);
    ($this->postWs)('cash-device-errors', ['events' => [$event]])->assertStatus(202);
    ($this->postWs)('cash-device-errors', ['events' => [$event]])->assertStatus(202);

    // Collector poll theo `pollInterval`, nên một sự cố kéo dài 2 phút sinh ra
    // hàng trăm lượt GẶP lỗi — mà đó vẫn là MỘT sự cố.
    expect(CashDeviceErrorEvent::query()->count())->toBe(1);
});

it('#2882 cleared_at tới ở lượt đẩy sau và ĐÓNG sự cố — đó là cách tính thời lượng', function () {
    $base = [
        'peripheral_device_id' => (string) $this->glory->id,
        'error_title' => 'empty',
        'error_group' => 'change_shortage',
        'occurred_at' => '2026-08-15T03:00:00Z',
    ];

    ($this->postWs)('cash-device-errors', ['events' => [$base]])->assertStatus(202);
    ($this->postWs)('cash-device-errors', ['events' => [
        $base + ['cleared_at' => '2026-08-15T03:04:00Z'],
    ]])->assertStatus(202);

    $row = CashDeviceErrorEvent::query()->sole();

    expect($row->cleared_at)->not->toBeNull()
        // 4 phút bị chặn bán hàng — con số quy ra tiền.
        ->and($row->occurred_at->diffInMinutes($row->cleared_at))->toBe(4.0);
});

it('#2882 nhóm lỗi ngoài từ vựng bị TỪ CHỐI ở validate', function () {
    // `IsBusy` là nhịp bình thường của giao thức. Cho nó vào sổ sẽ chôn lấp bốn
    // nhóm thật, và một sổ toàn rác sẽ bị tắt.
    ($this->postWs)('cash-device-errors', ['events' => [[
        'peripheral_device_id' => (string) $this->glory->id,
        'error_title' => 'busy',
        'error_group' => 'busy',
        'occurred_at' => '2026-08-15T03:00:00Z',
    ]]])->assertStatus(422);

    expect(CashDeviceErrorEvent::query()->count())->toBe(0);
});

it('#2882 máy của chi nhánh KHÁC bị từ chối', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $foreign = PeripheralDevice::factory()->create([
        'branch_id' => $otherBranch->id,
        'organization_id' => $this->orgId,
        'type' => 'coin_changer',
        'is_active' => true,
    ]);

    $res = ($this->postWs)('cash-device-errors', ['events' => [[
        'peripheral_device_id' => (string) $foreign->id,
        'error_title' => 'empty',
        'error_group' => 'change_shortage',
        'occurred_at' => '2026-08-15T03:00:00Z',
    ]]])->assertStatus(202);

    expect($res->json('rejected'))->toBe(1)
        ->and(CashDeviceErrorEvent::query()->count())->toBe(0);
});
