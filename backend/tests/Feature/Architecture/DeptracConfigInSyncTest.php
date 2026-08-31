<?php

declare(strict_types=1);

/**
 * #1532 (epic #962) — `deptrac.yaml` is GENERATED from `config/modules.php`.
 *
 * Two files describing the same module map is two sources of truth, and the
 * copy rots silently: a model added to the manifest would keep passing Deptrac
 * while belonging to no layer, so its dependencies would be counted as
 * "uncovered" instead of as debt. This test re-runs the generator and fails on
 * any difference, which makes the committed YAML a projection rather than a
 * second opinion.
 *
 * Why a generated file is committed at all: Deptrac reads YAML, not PHP, and
 * CI must be able to run `deptrac analyse` without booting Laravel first.
 */

use Illuminate\Support\Facades\Artisan;

it('D1: deptrac.yaml khớp config/modules.php', function () {
    $exit = Artisan::call('architecture:deptrac-config', ['--check' => true]);

    expect($exit)->toBe(
        0,
        "deptrac.yaml lệch với config/modules.php.\n".
        "Chạy: php artisan architecture:deptrac-config\n".
        Artisan::output()
    );
});

it('D2: baseline chỉ được co lại, và mỗi vi phạm là một khối riêng', function () {
    /*
     * Đây là lý do đổi công cụ. Bản cũ giữ MỘT con số cho mỗi cặp module trong
     * một file JSON, nên hai PR trả nợ ở hai class khác nhau vẫn sửa cùng một
     * dòng — ngày 2026-08-01 đo được 6/9 PR conflict ở đúng file đó, và `dev`
     * đỏ hai lần vì baseline cũ merge xen kẽ nhau.
     *
     * Deptrac ghi theo TỪNG CLASS. Test này ghim đúng tính chất đó: nếu ai đó
     * gộp lại thành một danh sách phẳng, conflict quay lại ngay.
     */
    $path = base_path('deptrac-baseline.yaml');

    expect(file_exists($path))->toBeTrue('Thiếu deptrac-baseline.yaml');

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    expect($lines[0] ?? '')->toBe('deptrac:')
        ->and($lines[1] ?? '')->toBe('  skip_violations:');

    // Mỗi khoá cấp 3 là một class, mỗi mục dưới nó là một dependency.
    $classKeys = 0;
    $items = 0;
    $orphanItems = 0;
    $sawClassKey = false;

    foreach (array_slice($lines, 2) as $line) {
        if ($line === '') {
            continue;
        }
        if (preg_match('/^    [A-Za-z\\\\]+:$/', $line) === 1) {
            $classKeys++;
            $sawClassKey = true;

            continue;
        }
        if (preg_match('/^      - [A-Za-z\\\\]+$/', $line) === 1) {
            $items++;
            if (! $sawClassKey) {
                $orphanItems++;   // một mục không nằm dưới class nào ⇒ danh sách phẳng
            }

            continue;
        }
        $this->fail("Dòng lạ trong baseline, không phải khoá class cũng không phải mục: {$line}");
    }

    /*
     * #962 — ngưỡng cũ là `count($classKeys) > 50`, và nó SAI KIỂU: nó trừng phạt
     * chính tiến độ mà epic này theo đuổi. Baseline co từ 156 xuống dưới 50 class
     * thì test đỏ, dù cấu trúc vẫn đúng y nguyên — tức nó đo NỢ trong khi ý định
     * là đo HÌNH DẠNG. Đã có `LayerCyclesTest` làm bánh cóc cho nợ rồi.
     *
     * Giờ nó ghim đúng thứ tên nó nói: baseline phải phân theo class. Một danh
     * sách phẳng sẽ có mục nằm ngoài mọi khoá class, và conflict quay lại ngay —
     * đó mới là thứ cần chặn.
     */
    expect($orphanItems)->toBe(
        0,
        'Baseline phải ghi theo từng class. Một danh sách phẳng đưa conflict quay lại.'
    );

    // Rào chống bài test này tự nói dối: file rỗng cũng thoả "không có mục mồ côi".
    expect($classKeys)->toBeGreaterThan(0, 'Baseline không có khoá class nào — cấu trúc đã hỏng.');
    expect($items)->toBeGreaterThanOrEqual($classKeys, 'Có khoá class không kèm dependency nào.');
});
