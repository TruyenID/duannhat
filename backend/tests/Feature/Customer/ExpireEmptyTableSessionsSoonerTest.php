<?php

/**
 * #3010 — phiên CHƯA CÓ ĐƠN NÀO phải được nhả SỚM hơn nhiều so với 4 giờ.
 *
 * ## Ca thật
 *
 * 本郷店, 18:04 CN 16/08/2026, giữa ca tối. Bàn C-1 `occupied` mà không có đơn
 * nào, trong khi tab bên cạnh của chính máy đó hiện `betoya.jp | 524`. Quét QR
 * lật `free → occupied` TRƯỚC khi có đơn (`CustomerTableSessionService`), nên
 * một lượt quét hỏng sau bước đó để lại đúng hình dạng này — và 4 giờ giữa ca
 * tối là mất một bàn cả buổi.
 *
 * ## Vì sao hai ngưỡng, không phải một ngưỡng ngắn hơn
 *
 * Phiên CÓ đơn = khách đang ăn; 4 giờ là đúng, cắt ngắn là đuổi khách khỏi bàn
 * của chính họ trên màn hình. Phiên KHÔNG đơn = quét rồi thôi — chưa từng có gì
 * xảy ra trên bàn đó.
 *
 * Nhả sớm rẻ vì nó **tự chữa**: khách còn ngồi mà quét lại thì `joinOrStart`
 * lật `free → occupied` lần nữa. Nhả muộn thì bàn nằm chết tới 4 giờ. Hai chiều
 * sai không cân nhau, nên rào lệch về phía nhả.
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

    /** Bàn `occupied` + phiên mở cách đây `$minutesAgo` phút. */
    $this->seated = function (int $minutesAgo): array {
        $table = Table::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'status' => 'occupied',
            'current_order_id' => null,
            'is_active' => true,
            'qr_token' => (string) Str::uuid(),
        ]);

        $session = TableSession::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'table_id' => $table->id,
            'status' => TableSession::STATUS_OPEN,
            'opened_at' => now()->subMinutes($minutesAgo),
        ]);

        return [$table, $session];
    };

    $this->tableStatus = fn (string $id) => DB::table('tables')->where('id', $id)->value('status');
});

// ─────────────────────────────────────────────────────────────────────────────
// PHẢI NHẢ
// ─────────────────────────────────────────────────────────────────────────────

it('#3010 quét QR rồi bỏ đi, chưa gọi món gì ⇒ nhả bàn sau 45 phút, không đợi 4 giờ', function () {
    [$table, $session] = ($this->seated)(60);

    $this->artisan('dine-in:expire-stale-sessions')->assertSuccessful();

    expect($session->fresh()->status)->toBe(TableSession::STATUS_EXPIRED)
        ->and(($this->tableStatus)($table->id))->toBe('free');
});

// ─────────────────────────────────────────────────────────────────────────────
// PHẢI IM — nửa nguy hiểm hơn: nhả nhầm là đuổi khách đang ăn khỏi bàn
// ─────────────────────────────────────────────────────────────────────────────

it('#3010 phiên CÓ đơn vẫn giữ ngưỡng 4 giờ — 60 phút KHÔNG đụng tới', function () {
    [$table, $session] = ($this->seated)(60);

    // Khách đã gọi món. Đây là bàn đang phục vụ thật.
    CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => CustomerOrderStatusEnum::Open,
        'table_session_id' => $session->id,
    ]);

    $this->artisan('dine-in:expire-stale-sessions')->assertSuccessful();

    expect($session->fresh()->status)->toBe(TableSession::STATUS_OPEN)
        ->and(($this->tableStatus)($table->id))->toBe('occupied');
});

it('#3010 đơn ĐÃ ĐÓNG vẫn tính là "đã từng gọi món" ⇒ giữ ngưỡng 4 giờ', function () {
    // Ranh giới là "phiên này đã từng có đơn chưa", KHÔNG phải "còn đơn sống
    // không". Khách trả xong ngồi lại uống nốt vẫn là khách đang ngồi; đuổi họ
    // sau 45 phút vì đơn đã đóng là sai đúng ở ca đông khách nhất.
    [$table, $session] = ($this->seated)(60);

    CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => CustomerOrderStatusEnum::Closed,
        'table_session_id' => $session->id,
    ]);

    $this->artisan('dine-in:expire-stale-sessions')->assertSuccessful();

    expect($session->fresh()->status)->toBe(TableSession::STATUS_OPEN);
});

it('#3010 phiên rỗng nhưng còn TRONG cửa sổ ⇒ chưa đụng tới', function () {
    // Khách vừa ngồi, đang xem menu. Nhả bàn lúc này là xoá họ khỏi màn hình
    // trong khi họ đang ngồi đó.
    [$table, $session] = ($this->seated)(20);

    $this->artisan('dine-in:expire-stale-sessions')->assertSuccessful();

    expect($session->fresh()->status)->toBe(TableSession::STATUS_OPEN)
        ->and(($this->tableStatus)($table->id))->toBe('occupied');
});

it('#3010 cửa sổ chỉnh được, và --dry-run không ghi gì', function () {
    [$table, $session] = ($this->seated)(20);

    $this->artisan('dine-in:expire-stale-sessions', ['--empty-minutes' => 10, '--dry-run' => true])
        ->assertSuccessful();

    expect($session->fresh()->status)->toBe(TableSession::STATUS_OPEN);

    $this->artisan('dine-in:expire-stale-sessions', ['--empty-minutes' => 10])->assertSuccessful();

    expect($session->fresh()->status)->toBe(TableSession::STATUS_EXPIRED)
        ->and(($this->tableStatus)($table->id))->toBe('free');
});
