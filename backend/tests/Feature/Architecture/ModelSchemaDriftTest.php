<?php

declare(strict_types=1);

/**
 * #1657 — ngân sách cho "model khai cột không tồn tại".
 *
 * Ba trục, ba con số, và hai trong ba là **0** — một kết quả âm có bằng chứng vẫn
 * là kết quả, và ghim nó ở 0 thì trục đó không bao giờ mở lại được trong im lặng.
 *
 * Ngân sách CHỈ ĐƯỢC GIẢM. Muốn tăng thì phải sửa file này, và diff đó là chỗ để
 * reviewer hỏi "vì sao".
 */

use App\Models\InvoiceCounter;
use App\Models\MenuMenuSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** `$fillable` khai cột ma — trục này SẠCH, giữ nguyên như vậy. */
const FILLABLE_DRIFT_BUDGET = 0;

/** `$casts` khai cột ma — cũng SẠCH. */
const CASTS_DRIFT_BUDGET = 0;

/**
 * `$primaryKey` không phải cột — còn 2, cả hai là bảng khoá kép mà Eloquent không
 * diễn đạt nổi. Không hạ được về 0 bằng cách sửa khai báo; xem `RefusesKeylessWrites`.
 */
const PRIMARY_KEY_DRIFT_BUDGET = 2;

function driftReport(): array
{
    $exit = Artisan::call('architecture:model-schema-drift', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        // exit khác 0 là bình thường khi còn ngân sách; chỉ cần lệnh CHẠY được.
        ->and($exit)->toBeIn([0, 1]);

    return $report;
}

it('quét được số model đáng kể — nếu không, con số 0 bên dưới là vô nghĩa', function () {
    // Bộ quét bỏ qua model không có bảng. Nếu một hôm nào đó nó bỏ qua GẦN HẾT
    // (đổi namespace, đổi cách nạp model), mọi ngân sách 0 vẫn xanh trong khi
    // thực chất không kiểm gì. Đây là bài kiểm "bộ quét còn sống".
    expect(driftReport()['models_scanned'])->toBeGreaterThan(150);
});

it('không model nào khai $fillable một cột không tồn tại', function () {
    $report = driftReport();

    expect($report['fillable_count'])->toBeLessThanOrEqual(FILLABLE_DRIFT_BUDGET, sprintf(
        "Trôi mới ở \$fillable:\n%s", json_encode($report['fillable'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    ));
});

it('không model nào khai $casts một cột không tồn tại', function () {
    $report = driftReport();

    expect($report['casts_count'])->toBeLessThanOrEqual(CASTS_DRIFT_BUDGET, sprintf(
        "Trôi mới ở \$casts:\n%s", json_encode($report['casts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    ));
});

it('chỉ còn hai model có $primaryKey không phải cột, và đúng hai cái đã biết', function () {
    $report = driftReport();

    expect($report['primary_key_count'])->toBeLessThanOrEqual(PRIMARY_KEY_DRIFT_BUDGET, sprintf(
        "Khoá chính ma MỚI:\n%s", json_encode($report['primary_key'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    ));

    $models = array_column($report['primary_key'], 'model');
    sort($models);

    expect($models)->toBe(['InvoiceCounter', 'MenuMenuSection']);
});

it('thuộc tính type: File nằm trong $fillable KHÔNG bị báo oan', function () {
    // `Product.thumbnail`, `Product.gallery`, `PointReward.image` là quan hệ morph
    // sang bảng `files`, có mặt trong $fillable là đúng quy ước Omnify. Nếu bộ trừ
    // dương-tính-giả hỏng, ba cái này quay lại và trục $fillable vọt lên 3.
    $attributes = array_column(driftReport()['fillable'], 'attribute');

    expect($attributes)->not->toContain('thumbnail')
        ->and($attributes)->not->toContain('gallery')
        ->and($attributes)->not->toContain('image');
});

it('save() qua model khoá-ma giờ NỔ, thay vì âm thầm không ghi gì', function () {
    // Tiền đề đo được trước khi có rào: save() trả true, DB không đổi.
    DB::table('menu_menu_sections')->insert([
        'menu_id' => (string) Str::uuid(),
        'menu_section_id' => (string) Str::uuid(),
        'display_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = MenuMenuSection::query()->first();
    expect($row)->not->toBeNull()
        ->and($row->getKey())->toBeNull();

    $row->display_order = 99;

    expect(fn () => $row->save())->toThrow(LogicException::class, 'WHERE NULL');
    expect(DB::table('menu_menu_sections')->value('display_order'))->toBe(1);
});

it('delete() qua model khoá-ma cũng NỔ — nó sẽ xoá 0 dòng', function () {
    DB::table('invoice_counters')->insert([
        'branch_id' => (string) Str::uuid(),
        'year_month' => '2026-08',
        'last_seq' => 7,
        'updated_at' => now(),
    ]);

    $row = InvoiceCounter::query()->first();

    expect(fn () => $row->delete())->toThrow(LogicException::class);
    expect(DB::table('invoice_counters')->count())->toBe(1);
});

it('INSERT vẫn chạy được — rào chỉ chặn update/delete', function () {
    // Lúc insert khoá null là bình thường và câu lệnh KHÔNG cần WHERE, nên chặn
    // chỗ này là chặn nhầm. Ghim lại để không ai "siết thêm cho chắc".
    InvoiceCounter::query()->create([
        'branch_id' => (string) Str::uuid(),
        'year_month' => '2026-09',
        'last_seq' => 1,
    ]);

    expect(DB::table('invoice_counters')->count())->toBe(1);
});
