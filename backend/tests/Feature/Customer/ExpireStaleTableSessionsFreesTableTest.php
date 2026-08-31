<?php

/**
 * #2524 — bàn ma: `tables.status = occupied` mà `current_order_id = null` và
 * không có đơn nào mở.
 *
 * Đo trên production 2026-08-12 (本郷店 C-1 · A-3 · B-3): khách quét QR làm bàn
 * thành `occupied` rồi bỏ đi không gọi món; `dine-in:expire-stale-sessions` ghi
 * log "Expired stale table session" và phiên đúng là `expired` trong DB — nhưng
 * **bàn vẫn `occupied`**, `updated_at` không nhích. POS thì vô hiệu hoá ô bàn
 * `occupied` không có đơn, nên nhân viên nhìn thấy một ô xám không bấm được.
 *
 * Bài test này ghim NGHĨA VỤ, không ghim cách cài đặt: chạy xong lệnh thì bàn
 * phải về `free` và `current_order_id` phải rỗng.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Table;
use App\Models\TableSession;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
});

/** Bàn đang `occupied` + một phiên MỞ đã quá hạn dọn. */
function ghostTableWithStaleSession(?string $currentOrderId = null): array
{
    $table = Table::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'status' => 'occupied',
        'current_order_id' => $currentOrderId,
        'is_active' => true,
        'qr_token' => (string) Str::uuid(),
    ]);

    $session = TableSession::create([
        'id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'table_id' => $table->id,
        'status' => TableSession::STATUS_OPEN,
        'opened_at' => now()->subHours(9),
    ]);

    return [$table, $session];
}

it('#2524 — phiên treo không còn đơn sống: bàn về free VÀ current_order_id được xoá', function () {
    // Bàn còn trỏ vào một đơn ĐÃ ĐÓNG — nửa còn lại của bàn ma mà lượt ghi cũ
    // (`update(['status' => 'free'])`) để nguyên. POS coi `current_order_id`
    // khác null là "đang phục vụ", nên bỏ sót cột này là bàn vẫn hiện sai.
    $closed = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => CustomerOrderStatusEnum::Closed,
    ]);

    [$table, $session] = ghostTableWithStaleSession((string) $closed->id);
    $closed->update(['table_session_id' => $session->id]);

    $this->artisan('dine-in:expire-stale-sessions')->assertSuccessful();

    expect($session->fresh()->status)->toBe(TableSession::STATUS_EXPIRED);

    // Đọc THÔ: cột là thứ POS đọc, và ca hỏng trên production là cột không đổi
    // trong khi phiên đã đổi.
    $row = DB::table('tables')->where('id', $table->id)->first();
    expect($row->status)->toBe('free')
        ->and($row->current_order_id)->toBeNull();
});

it('#2524 — phiên treo CÒN ĐƠN SỐNG thì giữ nguyên occupied, không nhả bàn', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);

    [$table, $session] = ghostTableWithStaleSession((string) $order->id);
    $order->update(['table_session_id' => $session->id]);

    $this->artisan('dine-in:expire-stale-sessions')->assertSuccessful();

    // Phiên vẫn hết hạn — nó quá 4 tiếng, đó là việc của lệnh này.
    expect($session->fresh()->status)->toBe(TableSession::STATUS_EXPIRED);

    // Nhưng bàn thì KHÔNG được trả về free: trả một bàn còn đơn chưa thanh toán
    // là mời khách sau ngồi vào đơn đang sống của khách trước.
    $row = DB::table('tables')->where('id', $table->id)->first();
    expect($row->status)->toBe('occupied')
        ->and($row->current_order_id)->toBe((string) $order->id);
});

it('#2524 — bàn đang cleaning/reserved không bị lượt dọn phiên kéo về free', function () {
    [$table, $session] = ghostTableWithStaleSession();
    DB::table('tables')->where('id', $table->id)->update(['status' => 'cleaning']);

    $this->artisan('dine-in:expire-stale-sessions')->assertSuccessful();

    expect($session->fresh()->status)->toBe(TableSession::STATUS_EXPIRED);

    // `cleaning` là trạng thái do người đặt; lượt dọn phiên chỉ gỡ đúng cái nó
    // gây ra (`occupied`), không quét dọn hộ trạng thái khác.
    $row = DB::table('tables')->where('id', $table->id)->first();
    expect($row->status)->toBe('cleaning');
});

it('#2524 — bàn đang giữ một đơn SỐNG của người khác thì không bị lượt dọn phiên cắt mất', function () {
    // Phiên QR treo từ trước, rồi nhân viên mở đơn thẳng trên POS cho đúng bàn
    // đó. Đơn mới KHÔNG thuộc phiên, nên phép kiểm "phiên còn đơn sống không"
    // trả về false — mà bàn thì đang giữ nó.
    //
    // Nhả bàn ở đây xoá `current_order_id` và cắt khách đang ngồi khỏi đơn của
    // họ. Bản cũ chỉ đổi `status` nên không cắt được; bản vá #2524 xoá cả cột,
    // nên rào này phải đi kèm.
    [$table, $session] = ghostTableWithStaleSession();

    $live = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    DB::table('tables')->where('id', $table->id)->update(['current_order_id' => $live->id]);

    $this->artisan('dine-in:expire-stale-sessions')->assertSuccessful();

    expect($session->fresh()->status)->toBe(TableSession::STATUS_EXPIRED);

    $row = DB::table('tables')->where('id', $table->id)->first();
    expect($row->status)->toBe('occupied')
        ->and($row->current_order_id)->toBe((string) $live->id);
});
