<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Shop\TableStatusService;
use Database\Factories\TableSessionFactory;

/**
 * #2610 — trả bàn về `free` phải đóng `TableSession` đang mở.
 *
 * Trước bản này, bốn route đổi trạng thái (pos · shop · workstation · tms) đều
 * chỉ ghi `tables.status`. Bàn bị khai là trống trong khi khách vẫn giữ phiên
 * trên điện thoại; phiên mồ côi sống tới 4 giờ chờ
 * `dine-in:expire-stale-sessions`, và một lần quét QR nữa trong khoảng đó mở
 * phiên THỨ HAI cho cùng cái bàn.
 *
 * Test đi qua `TableStatusService` chứ không qua HTTP: bốn route dùng CHUNG
 * service này, nên ghim ở đây phủ cả bốn. Ghim ở một route sẽ để ba đường kia
 * trôi đi mà không ai biết.
 */
beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create();
    $this->service = app(TableStatusService::class);
});

function tableWithOpenSessions(Branch $branch, int $count = 1, string $status = 'occupied'): Table
{
    $table = Table::factory()->create([
        'branch_id' => $branch->id,
        'status' => $status,
        'is_active' => true,
    ]);

    TableSessionFactory::new()->count($count)->create([
        'table_id' => $table->id,
        'status' => TableSession::STATUS_OPEN,
        'closed_at' => null,
    ]);

    return $table;
}

it('đổi bàn về free thì đóng phiên đang mở', function () {
    $table = tableWithOpenSessions($this->branch);

    $this->service->changeStatus($table, 'free', (string) $this->user->id);

    $session = TableSession::where('table_id', $table->id)->firstOrFail();
    expect($session->status)->toBe(TableSession::STATUS_CLOSED)
        ->and($session->closed_at)->not->toBeNull();
});

/**
 * `closed`, KHÔNG phải `expired` — ruling chủ dự án 2026-08-12.
 *
 * `expired` dành riêng cho reaper 4 giờ, tức "không ai quyết định gì". Dùng nó ở
 * đây sẽ làm "nhân viên dọn bàn" trông y hệt "khách bỏ đi mà không ai để ý", và
 * không truy vấn nào tách hai nguyên nhân đó ra được nữa.
 */
it('đóng bằng `closed` chứ không phải `expired`', function () {
    $table = tableWithOpenSessions($this->branch);

    $this->service->changeStatus($table, 'free', (string) $this->user->id);

    expect(TableSession::where('table_id', $table->id)->value('status'))
        ->toBe(TableSession::STATUS_CLOSED)
        ->not->toBe(TableSession::STATUS_EXPIRED);
});

/**
 * Bàn CÓ THỂ mang nhiều phiên mở cùng lúc — đó chính là tình trạng lỗi này sinh
 * ra (ép bàn về trống không đóng phiên ⇒ lần quét QR sau mở thêm phiên nữa).
 * Đo trên production 2026-08-12: ningyocho B-5 có ba phiên trong ngày.
 */
it('đóng MỌI phiên đang mở, không chỉ một', function () {
    $table = tableWithOpenSessions($this->branch, count: 3);

    $this->service->changeStatus($table, 'free', (string) $this->user->id);

    expect(TableSession::where('table_id', $table->id)
        ->where('status', TableSession::STATUS_OPEN)->count())->toBe(0);
});

/**
 * `cleaning` / `out_of_service` KHÔNG có nghĩa khách đã đi — bàn có thể đang chờ
 * dọn với phiên còn sống. Đóng phiên ở đó là đoán hộ nhân viên.
 */
it('KHÔNG đóng phiên khi đổi sang trạng thái khác free', function (string $to) {
    $table = tableWithOpenSessions($this->branch);

    $this->service->changeStatus($table, $to, (string) $this->user->id);

    expect(TableSession::where('table_id', $table->id)->value('status'))
        ->toBe(TableSession::STATUS_OPEN);
})->with(['cleaning', 'out_of_service', 'reserved', 'occupied']);

it('bàn không có phiên nào thì vẫn đổi được trạng thái, không nổ', function () {
    $table = Table::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'occupied',
        'is_active' => true,
    ]);

    $fresh = $this->service->changeStatus($table, 'free', (string) $this->user->id);

    expect($fresh->status->value ?? $fresh->status)->toBe('free');
});

/**
 * Phiên đã đóng bởi đường khác (đơn thanh toán xong, hoặc reaper chạy đúng lúc
 * đó) không được đụng lại — `closed_at` phải giữ nguyên dấu thời gian CŨ, nếu
 * không ta ghi đè mất lúc khách thật sự rời bàn.
 */
it('không giẫm lên phiên đã đóng trước đó', function () {
    $table = tableWithOpenSessions($this->branch);
    $old = now()->subHours(2);
    TableSession::where('table_id', $table->id)->update([
        'status' => TableSession::STATUS_CLOSED,
        'closed_at' => $old,
    ]);

    $this->service->changeStatus($table, 'free', (string) $this->user->id);

    // So bằng CHUỖI giây: `value()` trả raw string của driver, còn `$old` là
    // Carbon — `toEqual` trên hai kiểu đó so kiểu trước, và đỏ vì lý do không
    // liên quan gì tới điều đang ghim.
    expect(TableSession::where('table_id', $table->id)->firstOrFail()->closed_at->toDateTimeString())
        ->toBe($old->toDateTimeString());
});

/**
 * Phiên và bàn phải commit CÙNG NHAU. `changeStatus` chạy trong một transaction
 * đã khoá bàn (BR-T03); nếu phần đóng phiên tách ra ngoài thì một lỗi ở giữa sẽ
 * để lại đúng kiểu lệch mà bản vá này dọn — chỉ đổi chiều.
 */
it('phiên không bị đóng khi transaction rollback', function () {
    $table = tableWithOpenSessions($this->branch);

    try {
        DB::transaction(function () use ($table) {
            $this->service->changeStatus($table, 'free', (string) $this->user->id);
            throw new RuntimeException('ép rollback');
        });
    } catch (RuntimeException) {
        // mong đợi
    }

    expect(TableSession::where('table_id', $table->id)->value('status'))
        ->toBe(TableSession::STATUS_OPEN)
        ->and(Table::find($table->id)->status->value ?? Table::find($table->id)->status)
        ->toBe('occupied');
});
