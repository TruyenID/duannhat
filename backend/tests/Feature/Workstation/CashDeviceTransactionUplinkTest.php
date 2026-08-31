<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CashDeviceTransaction;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use Illuminate\Support\Str;

/*
 * T1 của #2876 (#2878) — sổ lượt thu tiền 釣銭機 đi lên Cloud.
 *
 * Mọi bài ở đây đi QUA ENDPOINT, không gọi thẳng service. Đó là điều kiện bắt
 * buộc chứ không phải sở thích: `$request->validate()` strip mọi key không có
 * rule (#2622), nên một cột thiếu rule vẫn đi hết đường service và mọi test
 * service-level vẫn xanh — trong khi tính năng chết im lặng trên đường thật.
 *
 * KHÔNG có bài cho nhánh "thiết bị chưa gắn chi nhánh" của controller:
 * `devices.branch_id` là NOT NULL trong migration thật, nên trạng thái đó
 * KHÔNG dựng được. Guard vẫn giữ vì `AlertController` anh em có cùng nhánh và
 * nó rẻ — nhưng nó là phòng thủ, không phải đường chạy được, và một bài test
 * giả vờ dựng được nó sẽ nói dối về độ phủ.
 *
 * Bộ bài chia hai nửa cố ý: nửa CHỨNG MINH GHI ĐƯỢC và nửa CHỨNG MINH IM.
 * Một rào chỉ biết kêu mà không biết im sẽ bị tắt chứ không bị tranh luận.
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
        'name' => 'glory-01',
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->push = fn (array $rows) => $this
        ->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/cash-device-transactions', ['transactions' => $rows]);

    $this->row = fn (array $over = []) => array_merge([
        'peripheral_device_id' => (string) $this->glory->id,
        'glory_transaction_id' => 'T-001',
        'outcome' => 'finish',
        'requested_minor' => 1000,
        'deposited_minor' => 1000,
        'change_minor' => 0,
        'dispensed_minor' => 0,
        'machine_seq_no' => 100,
        'started_at' => '2026-08-15T03:00:00Z',
        'finished_at' => '2026-08-15T03:00:20Z',
    ], $over);
});

// ─────────────────────────────────────────────────────────────────────────────
// Chiều GHI ĐƯỢC
// ─────────────────────────────────────────────────────────────────────────────

it('#2878 ghi lượt TIMEOUT — máy giữ tiền mà KHÔNG có dòng order_payments nào', function () {
    // Đây là lý do cả bảng tồn tại. Trước T1, kết cục này không để lại dấu vết
    // nào trên Cloud: `order_payments` chỉ có hàng khi thu ĐƯỢC tiền.
    ($this->push)([($this->row)([
        'glory_transaction_id' => 'T-timeout',
        'outcome' => 'timeout',
        'requested_minor' => 1500,
        'deposited_minor' => 900,
        'finished_at' => null,
    ])])->assertStatus(202);

    $row = CashDeviceTransaction::query()->where('glory_transaction_id', 'T-timeout')->sole();

    expect($row->outcome->value)->toBe('timeout')
        // Tiền khách đã bỏ vào máy — con số quan trọng nhất của cả hàng.
        ->and((int) $row->deposited_minor)->toBe(900)
        // NULL ở đây là ngữ nghĩa vĩnh viễn (BR-CDT02), không phải dữ liệu thiếu.
        ->and($row->order_payment_id)->toBeNull()
        ->and($row->branch_id)->toBe((string) $this->branch->id);
});

it('#2878 tự phân giải order_payment từ idempotency_key glory:<mã>, không tin FK thiết bị gửi', function () {
    $payment = OrderPayment::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'idempotency_key' => 'glory:T-paid',
        'reference_no' => 'T-paid',
    ]);

    ($this->push)([($this->row)(['glory_transaction_id' => 'T-paid'])])->assertStatus(202);

    expect(CashDeviceTransaction::query()->where('glory_transaction_id', 'T-paid')->sole()->order_payment_id)
        ->toBe((string) $payment->id);
});

it('#2878 gửi lại với seq MỚI HƠN thì cập nhật, không đẻ hàng thứ hai', function () {
    ($this->push)([($this->row)(['outcome' => 'finish', 'dispensed_minor' => 0, 'machine_seq_no' => 100])])
        ->assertStatus(202);

    $res = ($this->push)([($this->row)(['outcome' => 'failure', 'dispensed_minor' => 300, 'machine_seq_no' => 200])])
        ->assertStatus(202);

    expect($res->json('updated'))->toBe(1)
        ->and(CashDeviceTransaction::query()->where('glory_transaction_id', 'T-001')->count())->toBe(1);

    $row = CashDeviceTransaction::query()->where('glory_transaction_id', 'T-001')->sole();

    expect($row->outcome->value)->toBe('failure')
        ->and((int) $row->dispensed_minor)->toBe(300);
});

it('#2878 lô quá 50 bị chặn — máy trạm phải tự cắt trước khi gửi', function () {
    // Vượt ngưỡng thì CẢ LÔ rơi, kể cả hàng quan trọng nhất. Con số ở hai đầu
    // phải khớp; đây là vế Cloud của ràng buộc đó.
    $rows = [];

    for ($i = 0; $i < 51; $i++) {
        $rows[] = ($this->row)(['glory_transaction_id' => "T-{$i}"]);
    }

    ($this->push)($rows)->assertStatus(422);

    expect(CashDeviceTransaction::query()->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Chiều IM
// ─────────────────────────────────────────────────────────────────────────────

it('#2878 gửi lại Y HỆT thì im — không đổi gì, không đẻ hàng', function () {
    ($this->push)([($this->row)()])->assertStatus(202);

    $before = CashDeviceTransaction::query()->where('glory_transaction_id', 'T-001')->sole();

    $res = ($this->push)([($this->row)()])->assertStatus(202);

    expect($res->json('skipped_stale'))->toBe(1)
        ->and($res->json('accepted'))->toBe(0)
        ->and(CashDeviceTransaction::query()->count())->toBe(1)
        ->and(CashDeviceTransaction::query()->sole()->updated_at->equalTo($before->updated_at))->toBeTrue();
});

it('#2878 seq CŨ HƠN không ghi đè hàng mới — đồng hồ máy trạm không tham gia phép so', function () {
    // Máy trạm chạy offline dài ngày thì đồng hồ nó trôi. Trọng tài phải là
    // seqNo do ADAPTER phát, nếu không một hàng cũ sẽ đè lên hàng mới.
    ($this->push)([($this->row)(['outcome' => 'failure', 'machine_seq_no' => 500])])->assertStatus(202);

    $res = ($this->push)([($this->row)(['outcome' => 'finish', 'machine_seq_no' => 100])])->assertStatus(202);

    expect($res->json('skipped_stale'))->toBe(1)
        ->and(CashDeviceTransaction::query()->sole()->outcome->value)->toBe('failure');
});

it('#2878 máy của CHI NHÁNH KHÁC bị từ chối riêng hàng đó, phần còn lại vẫn vào', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $foreign = PeripheralDevice::factory()->create([
        'branch_id' => $otherBranch->id,
        'organization_id' => $this->orgId,
        'type' => 'coin_changer',
    ]);

    $res = ($this->push)([
        ($this->row)(['peripheral_device_id' => (string) $foreign->id, 'glory_transaction_id' => 'T-foreign']),
        ($this->row)(['glory_transaction_id' => 'T-mine']),
    ])->assertStatus(202);

    // Vứt CẢ LÔ vì một FK lạc là đánh mất bằng chứng tiền — sai chiều.
    expect($res->json('rejected'))->toBe(1)
        ->and($res->json('accepted'))->toBe(1)
        ->and(CashDeviceTransaction::query()->where('glory_transaction_id', 'T-foreign')->exists())->toBeFalse()
        ->and(CashDeviceTransaction::query()->where('glory_transaction_id', 'T-mine')->exists())->toBeTrue();
});

it('#2878 đơn/ca của chi nhánh khác bị BỎ GIÁ TRỊ, hàng vẫn giữ', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    ($this->push)([($this->row)([
        'customer_order_id' => (string) Str::uuid(),
        'till_session_id' => (string) Str::uuid(),
    ])])->assertStatus(202);

    $row = CashDeviceTransaction::query()->sole();

    expect($row->customer_order_id)->toBeNull()
        ->and($row->till_session_id)->toBeNull()
        // Hàng vẫn còn — bằng chứng tiền không được mất vì một FK không tra được.
        ->and((int) $row->deposited_minor)->toBe(1000);
});
