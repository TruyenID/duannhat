<?php

declare(strict_types=1);

/**
 * #1559 (epic #962) — hai sổ sở hữu phải nói cùng một thứ.
 *
 * Repo này có HAI sổ, dựng ở hai thời điểm, cho hai mục đích, và không sổ nào
 * biết sổ kia tồn tại:
 *
 *   config/modules.php                 9 module   sở hữu CLASS  → Deptrac
 *   config/domain-mutation-guard.php   7 aggregate sở hữu BẢNG  → DomainMutationGuard
 *
 * Không tên nào khớp tên nào. Hệ quả không hiện ra ở chỗ dễ thấy: một lệnh ghi
 * hợp lệ theo aggregate vẫn có thể vượt ranh giới MODULE mà không cổng nào bắt,
 * bởi vì Deptrac chỉ đọc `use` tĩnh (không thấy `DB::table()`), còn guard chỉ
 * biết aggregate (không biết module). Mỗi cổng kín một nửa, và nửa hở của chúng
 * KHÔNG chồng lên nhau.
 *
 * ADR 0001 và `ModuleRegistry` đã nêu đúng rủi ro này bằng chữ — *"hai sổ đăng
 * ký gọi cùng một thứ bằng hai tên khác nhau chính là cách bản đồ cũ trôi mất"* —
 * nhưng chưa có gì cưỡng chế nó giữa hai FILE CONFIG. Đây là chỗ cưỡng chế.
 *
 * Bài test này KHÔNG đòi hai sổ trùng tên. Nó đòi hai điều yếu hơn và giữ được:
 *
 *   L1  mỗi aggregate chiếu về ĐÚNG MỘT module (không vắt qua ranh giới)
 *   L2  số bảng chưa có chủ aggregate chỉ được GIẢM
 *
 * Cả hai đều là bánh cóc, không phải cấm đoán: mức hiện tại được ghim, và chỉ đi
 * xuống. Cấm tuyệt đối ở đây sẽ chỉ khiến người ta tắt cổng — bài học đã trả giá
 * ở #1532 khi bánh cóc cũ bị `--skip` vì nó cấm thay vì siết.
 */

use Illuminate\Support\Facades\File;

/**
 * Aggregate đang vắt qua nhiều module. CHỈ ĐƯỢC CO LẠI.
 *
 * Vì sao chúng vắt, để người sau không phải đo lại:
 *   menu   Catalog 17 · Pricing 4 · Organization 1 — giá/khuyến mãi và lịch mở
 *          cửa của chi nhánh sống chung bảng với menu
 *
 * `order` ĐÃ RA KHỎI danh sách này ở #1564: khi đó `OrderAdjustment` và
 * `OrderAdjustmentAllocation` (đã xoá ở #2041) mang tên `Order*` nhưng bị khai
 * dưới Pricing, và
 * đo ra thì CHỈ Ordering đọc chúng (19 + 6 lần, không module nào khác). Khai về
 * Ordering thì aggregate `order` chiếu trọn vào một module.
 *
 * Đáng ghi lại: chính bài test này bắt được thay đổi đó — bánh cóc chiều ngược
 * đỏ lên và buộc phải hạ danh sách. Hai phép đo độc lập (cạnh layer của Deptrac,
 * và cầu nối aggregate↔module ở đây) cùng chỉ về một kết luận.
 */
const STRADDLING_AGGREGATES = ['menu'];

/**
 * Bảng của FRAMEWORK — không thuộc domain nào và sẽ không bao giờ thuộc.
 *
 * Miễn tường minh chứ không nhét vào ngân sách: gộp chúng vào con số nợ khiến
 * ngân sách không bao giờ về 0 được, và một ngân sách không thể đạt là ngân sách
 * người ta thôi nhìn.
 *
 * Danh sách CHỈ ĐƯỢC CO LẠI — bánh cóc ở cuối file cưỡng chế. `migrations` đã
 * rời khỏi đây 2026-08-18: nó **chưa bao giờ** vào được tập bị trừ, vì bảng đó
 * do MIGRATOR của Laravel tự tạo chứ không có `Schema::create('migrations')`
 * nào trong `database/migrations` (đo lại: 0 file; 7 bảng còn lại đều 1 file).
 * Miễn trừ trừ một thứ không có mặt không phải là dọn dẹp — nó ngồi sẵn ở đúng
 * cái tên đó và nuốt trước bất cứ bảng NGHIỆP VỤ nào sau này mang tên trùng.
 */
const FRAMEWORK_TABLES = [
    'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs',
    'password_reset_tokens', 'sessions',
];

/** Bảng NGHIỆP VỤ chưa thuộc aggregate nào, đo lại 2026-08-07. CHỈ ĐƯỢC GIẢM. */
const UNOWNED_TABLE_BUDGET = 106;

/** @return array<string, string> tên model ngắn → module sở hữu */
function modelToModule(): array
{
    $out = [];
    foreach ((array) config('modules.modules', []) as $module => $spec) {
        foreach ((array) ($spec['models'] ?? []) as $model) {
            $out[$model] = $module;
        }
    }

    return $out;
}

/**
 * Tên bảng CÒN TỒN TẠI sau khi chạy hết migration — `Schema::create` trừ đi
 * `Schema::drop*`.
 *
 * Phải trừ, vì migration tạo bảng KHÔNG được xoá khỏi lịch sử khi bảng bị khai
 * tử: những môi trường đã chạy nó vẫn phải chạy lại được từ đầu. Chỉ đếm
 * `create` thì một bảng đã drop vẫn bị đòi có chủ vĩnh viễn, và cách duy nhất
 * làm rào xanh lại là khai chủ cho một bảng không tồn tại — tức nói dối đúng
 * chỗ rào này sinh ra để canh (#2041, khi plan-049 bị gỡ).
 *
 * @return list<string>
 */
function tablesCreatedByMigrations(): array
{
    $created = [];
    $dropped = [];

    foreach (File::allFiles(base_path('database/migrations')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = $file->getContents();

        $createsHere = [];
        if (preg_match_all('/Schema::create\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source, $m)) {
            foreach ($m[1] as $t) {
                $created[$t] = true;
                $createsHere[$t] = true;
            }
        }

        // `drop` / `dropIfExists`, KHÔNG phải `dropColumn`/`dropIndex`.
        //
        // Bỏ qua bảng mà CHÍNH migration này tạo: `down()` của mọi migration
        // create đều drop lại bảng của nó, nên đếm cả hai chiều sẽ khử sạch
        // 100% bảng và biến rào thành vô nghĩa (đo được: 0 bảng còn lại). Chỉ
        // migration drop một bảng do NGƯỜI KHÁC tạo mới là khai tử thật.
        if (preg_match_all('/Schema::(?:dropIfExists|drop)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source, $m)) {
            foreach ($m[1] as $t) {
                if (! isset($createsHere[$t])) {
                    $dropped[$t] = true;
                }
            }
        }
    }

    return array_values(array_diff(array_keys($created), array_keys($dropped)));
}

it('L1: mỗi aggregate chiếu về đúng MỘT module — chỗ vắt chỉ được co lại', function () {
    $modelToModule = modelToModule();
    $straddling = [];
    $detail = [];

    foreach ((array) config('domain-mutation-guard.aggregates', []) as $aggregate => $spec) {
        $modules = [];
        foreach ((array) ($spec['models'] ?? []) as $model) {
            $modules[$modelToModule[$model] ?? '(không module nào sở hữu)'][] = $model;
        }

        if (count($modules) <= 1) {
            continue;
        }

        $straddling[] = $aggregate;
        $parts = [];
        foreach ($modules as $module => $models) {
            $parts[] = $module.' ('.count($models).': '.implode(', ', array_slice($models, 0, 4)).')';
        }
        $detail[$aggregate] = implode(' · ', $parts);
    }

    sort($straddling);
    $allowed = STRADDLING_AGGREGATES;
    sort($allowed);

    $new = array_values(array_diff($straddling, $allowed));
    expect($new)->toBe([], sprintf(
        "Aggregate MỚI vắt qua nhiều module: %s\n\n%s\n".
        "Ghi bảng qua aggregate này vượt ranh giới module mà Deptrac KHÔNG thấy\n".
        '(nó chỉ đọc `use` tĩnh). Sửa ranh giới, đừng thêm vào danh sách.',
        implode(', ', $new),
        implode("\n", array_map(
            static fn (string $a): string => "  {$a}: ".($detail[$a] ?? ''),
            $new,
        )),
    ));

    $fixed = array_values(array_diff($allowed, $straddling));
    expect($fixed)->toBe([], sprintf(
        "TIN TỐT — %s không còn vắt qua module nữa.\n".
        'Bỏ khỏi STRADDLING_AGGREGATES, nếu không lần vắt sau sẽ đi lọt vào chỗ trống đó.',
        implode(', ', $fixed),
    ));
});

it('L2: số bảng chưa có chủ aggregate chỉ được GIẢM', function () {
    $owned = [];
    foreach ((array) config('domain-mutation-guard.aggregates', []) as $aggregate => $spec) {
        foreach ((array) ($spec['tables'] ?? []) as $table) {
            $owned[$table] = $aggregate;
        }
    }

    $unowned = array_values(array_diff(
        tablesCreatedByMigrations(),
        array_keys($owned),
        FRAMEWORK_TABLES,
    ));
    sort($unowned);

    expect(count($unowned))->toBeLessThanOrEqual(UNOWNED_TABLE_BUDGET, sprintf(
        "Bảng chưa thuộc aggregate nào: %d (ngân sách %d).\n".
        "Bảng không có chủ = ghi vào nó KHÔNG cổng nào kiểm.\n".
        "Thêm bảng mới thì khai chủ trong config/domain-mutation-guard.php.\n\n".
        'Chưa có chủ: %s',
        count($unowned),
        UNOWNED_TABLE_BUDGET,
        implode(', ', array_slice($unowned, 0, 25)).(count($unowned) > 25 ? ' …' : ''),
    ));

    expect(count($unowned))->toBeGreaterThanOrEqual(UNOWNED_TABLE_BUDGET - 10, sprintf(
        "Còn %d bảng chưa có chủ, ngân sách vẫn ghi %d — hạ ngân sách xuống.\n".
        'Ngân sách không hạ theo thì lần tăng sau đi lọt vào phần chênh.',
        count($unowned),
        UNOWNED_TABLE_BUDGET,
    ));
});

/**
 * BÁNH CÓC — `FRAMEWORK_TABLES` chỉ được CO LẠI.
 *
 * Mỗi mục ở đây là một phép TRỪ khỏi mẫu số của L2. Trừ một tên KHÔNG có trong
 * `tablesCreatedByMigrations()` thì hôm nay không trừ gì — nhưng nó không nằm
 * yên: ngày một migration tạo bảng nghiệp vụ mang đúng cái tên đó, bảng ấy ra
 * đời với sẵn một miễn trừ, không bao giờ vào ngân sách, không cổng nào đòi chủ.
 *
 * Đây đúng là chuyện `migrations` đã làm suốt: bảng đó do migrator của Laravel
 * tạo, không có `Schema::create` nào, nên mục miễn trừ chưa bao giờ trừ gì.
 */
it('bánh cóc — miễn trừ bảng framework phải trừ một bảng CÓ THẬT', function () {
    $created = tablesCreatedByMigrations();

    // Bộ quét hỏng ⇒ tập rỗng ⇒ tố oan mọi mục. Ghim mẫu số trước.
    expect(count($created))->toBeGreaterThan(50, 'gần như không parse được Schema::create nào — bộ quét hỏng, không phải danh sách');

    foreach (FRAMEWORK_TABLES as $table) {
        expect(in_array($table, $created, true))->toBeTrue(implode("\n", [
            "Miễn trừ `{$table}` HẾT ỨNG: không migration nào trong database/migrations",
            "tạo bảng đó (`Schema::create('{$table}')` = 0 lần), nên mục này không trừ",
            'gì khỏi mẫu số của L2. Xoá nó khỏi FRAMEWORK_TABLES.',
            '',
            'Nó không vô hại: nó cho phép TRƯỚC — ngày một bảng NGHIỆP VỤ ra đời mang',
            'đúng tên đó, bảng ấy sẽ không bao giờ vào ngân sách "chưa có chủ".',
            '',
            'Danh sách này chỉ ĐI XUỐNG.',
        ]));
    }
});
