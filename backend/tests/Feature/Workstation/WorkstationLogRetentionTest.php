<?php

declare(strict_types=1);

/**
 * #2901 — hạn giữ 14 ngày của log máy trạm, và lượt đánh dấu yêu cầu hết hạn.
 *
 * Bài quan trọng nhất ở đây là bài chứng minh mốc đếm là **`logged_at`**, chứ
 * không phải `created_at`. Hai cột này gần bằng nhau trên máy đang online, nên
 * một bài viết cẩu thả sẽ xanh với CẢ HAI cách cài đặt — và cách sai chỉ lộ ra
 * ở đúng quán vừa mất mạng dài, tức đúng lúc dữ liệu nhạy cảm nhất nằm lại lâu
 * hơn mức đã cam kết. Bài dưới đây dựng một dòng `logged_at` cũ 20 ngày nhưng
 * `created_at` mới toanh — nó đỏ ngay nếu lệnh đếm nhầm cột.
 */

use App\Models\WorkstationLogRecord;
use App\Models\WorkstationLogRequest;
use App\Omnify\Enums\WorkstationLogRequestStatusEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ghi thẳng một dòng với `logged_at` và `created_at` ĐỘC LẬP nhau.
 *
 * Không đi qua factory vì factory sẽ đóng `created_at` theo đồng hồ, và cả
 * điểm của bài đo là tách hai cột ấy ra.
 */
function wsLogRecordAged(string $loggedAt, string $createdAt): string
{
    $id = (string) Str::uuid();

    DB::table('workstation_log_records')->insert([
        'id' => $id,
        'device_id' => (string) Str::uuid(),
        'local_id' => random_int(1, 1_000_000),
        'branch_id' => (string) Str::uuid(),
        'organization_id' => (string) Str::uuid(),
        'request_id' => (string) Str::uuid(),
        'logged_at' => $loggedAt,
        'level' => 'warn',
        'message' => 'sync push failed',
        'attrs' => null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16T10:00:00Z'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('#2901 xoá đúng những dòng quá 14 ngày, và KHÔNG chạm dòng mới hơn', function () {
    $old = wsLogRecordAged('2026-07-20 00:00:00', '2026-07-20 00:00:00');       // 27 ngày
    $justOver = wsLogRecordAged('2026-08-01 09:00:00', '2026-08-01 09:00:00');  // 15 ngày
    $justUnder = wsLogRecordAged('2026-08-03 09:00:00', '2026-08-03 09:00:00'); // 13 ngày
    $fresh = wsLogRecordAged('2026-08-16 09:00:00', '2026-08-16 09:00:00');     // 1 giờ

    $this->artisan('workstation-logs:prune')->assertExitCode(0);

    $left = WorkstationLogRecord::query()->pluck('id')->all();

    expect($left)->not->toContain($old)
        ->and($left)->not->toContain($justOver)
        // Vế "KHÔNG xoá bản ghi mới hơn" là nửa còn lại của rào: một lệnh xoá
        // sạch bảng cũng làm bài "đã xoá dòng cũ" xanh.
        ->and($left)->toContain($justUnder)
        ->and($left)->toContain($fresh)
        ->and($left)->toHaveCount(2);
});

it('#2901 mốc là `logged_at`, KHÔNG phải `created_at` — quán mất mạng dài không được kéo dài hạn giữ', function () {
    // Quán offline 20 ngày rồi mới đẩy được: dòng RA ĐỜI ngày 27/07 nhưng
    // Cloud NHẬN hôm nay. Đếm theo lúc nhận thì nó sống thêm 14 ngày nữa —
    // hạn 14 ngày lặng lẽ thành 34, và không ai khai điều đó.
    $offlineBacklog = wsLogRecordAged('2026-07-27 00:00:00', '2026-08-16 09:59:00');

    // Ảnh gương: dòng vừa sinh ra nhưng vì lý do nào đó `created_at` cũ. Nó
    // phải SỐNG — đếm nhầm cột theo chiều ngược lại cũng là mất dữ liệu.
    $freshButOldRow = wsLogRecordAged('2026-08-16 09:00:00', '2026-07-01 00:00:00');

    $this->artisan('workstation-logs:prune')->assertExitCode(0);

    $left = WorkstationLogRecord::query()->pluck('id')->all();

    expect($left)->not->toContain($offlineBacklog)
        ->and($left)->toContain($freshButOldRow);
});

it('#2901 --dry-run đếm mà KHÔNG xoá', function () {
    wsLogRecordAged('2026-07-01 00:00:00', '2026-07-01 00:00:00');
    wsLogRecordAged('2026-07-02 00:00:00', '2026-07-02 00:00:00');

    $this->artisan('workstation-logs:prune --dry-run')->assertExitCode(0);

    expect(WorkstationLogRecord::query()->count())->toBe(2);
});

it('#2901 cửa sổ giữ vượt TRẦN ⇒ từ chối chạy, không xoá gì', function () {
    // Ảnh gương của `audit:prune` (sàn PCI, giữ đủ lâu). Ở đây nghĩa vụ ngược
    // lại — đừng giữ quá lâu — nên rào là TRẦN. Và từ chối chứ không tự cắt
    // xuống: im lặng "sửa hộ" một cấu hình sai là cách chắc nhất để không ai
    // biết nó sai.
    $old = wsLogRecordAged('2026-07-01 00:00:00', '2026-07-01 00:00:00');

    $ceiling = (int) config('workstation_logs.retention_max_days');

    $this->artisan('workstation-logs:prune --days='.($ceiling + 1))->assertExitCode(1);

    expect(WorkstationLogRecord::query()->pluck('id')->all())->toContain($old);
});

it('#2901 cửa sổ giữ < 1 ngày ⇒ từ chối — lượt kéo vừa xong không được xoá trước khi ai kịp đọc', function () {
    $fresh = wsLogRecordAged('2026-08-16 09:00:00', '2026-08-16 09:00:00');

    $this->artisan('workstation-logs:prune --days=0')->assertExitCode(1);

    expect(WorkstationLogRecord::query()->pluck('id')->all())->toContain($fresh);
});

it('#2901 --max-rows chặn lượt chạy lại, phần còn lại để lượt sau', function () {
    for ($i = 0; $i < 5; $i++) {
        wsLogRecordAged('2026-07-0'.($i + 1).' 00:00:00', '2026-07-01 00:00:00');
    }

    $this->artisan('workstation-logs:prune --max-rows=2 --chunk=1')->assertExitCode(0);

    // Dừng sớm là kết cục BÌNH THƯỜNG, không phải lỗi: mốc được tính lại từ
    // đồng hồ ở lượt sau và tồn đọng rút dần qua nhiều đêm.
    expect(WorkstationLogRecord::query()->count())->toBe(3);
});

it('#2901 yêu cầu pending quá hạn chuyển sang expired — nhưng fulfilled thì KHÔNG bị đụng', function () {
    $stale = WorkstationLogRequest::factory()->stale()->create();
    $alive = WorkstationLogRequest::factory()->create();
    $done = WorkstationLogRequest::factory()->fulfilled(3)->create([
        'expires_at' => CarbonImmutable::now('UTC')->subDay(),
    ]);

    $this->artisan('workstation-logs:prune')->assertExitCode(0);

    expect($stale->fresh()->status)->toBe(WorkstationLogRequestStatusEnum::Expired)
        ->and($alive->fresh()->status)->toBe(WorkstationLogRequestStatusEnum::Pending)
        // `fulfilled` mang một khẳng định ("đã trả lời, được N dòng"); đè nó
        // thành `expired` sẽ xoá mất chính khẳng định đó.
        ->and($done->fresh()->status)->toBe(WorkstationLogRequestStatusEnum::Fulfilled)
        ->and($done->fresh()->received_count)->toBe(3);
});

it('#2901 lệnh có tên đúng như docs và như lịch đã đăng ký', function () {
    // `ArtisanCommandReferencesExistTest` đối chiếu mọi neo `artisan <lệnh>`
    // trong docs với `Artisan::all()`; bài này chốt chiều còn lại — tên lệnh
    // tồn tại thật.
    expect(array_keys(Artisan::all()))->toContain('workstation-logs:prune');
});
