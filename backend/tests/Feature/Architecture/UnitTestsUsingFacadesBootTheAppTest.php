<?php

declare(strict_types=1);

/**
 * #2753 — một test dùng Facade phải TỰ ĐẢM BẢO app được boot.
 *
 * `Pest.php` gán Laravel `TestCase` theo TỪNG thư mục: `Feature`, `Arch`,
 * `Unit/Promotion`, `Unit/Exceptions`, `Unit/Casts`, `Unit/Services/Settlement`,
 * `Browser`. Mỗi dòng đều có comment kể nó được thêm vì một unit test cụ thể cần
 * app. File nằm THẲNG trong `tests/Unit/` không dính dòng nào.
 *
 * Một file như thế mà gọi Facade vẫn XANH gần như mọi lúc, vì một test khác đã
 * boot app trước đó trong cùng tiến trình. Nó chỉ nổ khi có Feature test TEAR
 * DOWN app (xoá facade root) rồi mới tới lượt nó — tức phụ thuộc THỨ TỰ, và thứ
 * tự thì mặc định ổn định nên lỗi ngủ yên.
 *
 * `OrderPricingCalculatorTest` ngủ như vậy cho tới khi `flake-hunt` (nightly,
 * `--order-by=random`) đánh thức ngày 2026-08-12, seed 69625575: 1 failed /
 * 9670 passed, `BindingResolutionException`.
 *
 * Rào này bắt lớp lỗi đó lúc PR thay vì đợi một seed xui xẻo.
 */
it('mọi test ngay trong tests/Unit/ mà dùng Facade đều tự khai uses(TestCase::class)', function () {
    $dir = base_path('tests/Unit');

    // CHỈ maxdepth 1. Thư mục con đã được `Pest.php` bind, và quét đệ quy sẽ gộp
    // chúng vào rồi báo oan — đúng cái đã xảy ra khi điều tra #2753: pathspec
    // `tests/Unit/*.php` của git khớp ĐỆ QUY, cho ra 65 file thay vì 6, và suýt
    // biến một lỗi lẻ thành "lỗ hổng hệ thống".
    $files = glob($dir.'/*.php') ?: [];

    expect($files)->not->toBeEmpty(
        'Không tìm thấy file nào ngay trong tests/Unit/ — bố cục đã đổi, đọc lại rào này '
        .'thay vì tin rằng nó đang canh.'
    );

    $facades = ['Log', 'Cache', 'Config', 'DB', 'Event', 'Queue', 'Storage', 'Http', 'Bus', 'Mail', 'Notification'];
    $pattern = '/\b('.implode('|', $facades).')::/';

    $offenders = [];

    foreach ($files as $file) {
        $src = file_get_contents($file) ?: '';

        if (! preg_match($pattern, $src)) {
            continue;
        }

        // `uses(...)` bất kỳ là đủ: file tự khai thì nó tự chịu trách nhiệm.
        if (preg_match('/^\s*uses\(/m', $src)) {
            continue;
        }

        $offenders[] = basename($file);
    }

    expect($offenders)->toBe([], implode("\n", [
        'Các file sau nằm ngay trong tests/Unit/, gọi Facade, nhưng không khai uses():',
        '  '.implode("\n  ", $offenders),
        '',
        'Chúng sẽ XANH cho tới khi một Feature test tear down app ngay trước chúng —',
        'rồi đỏ với BindingResolutionException ở một seed ngẫu nhiên nào đó (#2753).',
        'Thêm `uses(TestCase::class);` vào đầu file.',
        'Chỉ thêm `RefreshDatabase` nếu test THẬT SỰ đụng DB: dựng DB cho một hàm',
        'thuần là trả giá thật cho một nhu cầu không có.',
    ]));
});

/**
 * Rào trên chỉ biết nói "không có file xấu". Nó không biết nói "bộ dò còn nhìn
 * thấy gì" — nên nếu danh sách Facade mục đi, hoặc regex hỏng, nó sẽ xanh vĩnh
 * viễn vì không tìm thấy gì để kiểm.
 *
 * Bài này ghim mẫu số: phải có ÍT NHẤT một file ngay trong `tests/Unit/` thật sự
 * khớp bộ dò Facade. Ngày viết rào, `OrderPricingCalculatorTest` là file đó.
 */
it('bộ dò Facade thật sự khớp được thứ gì đó trong tests/Unit/', function () {
    $files = glob(base_path('tests/Unit').'/*.php') ?: [];

    $facades = ['Log', 'Cache', 'Config', 'DB', 'Event', 'Queue', 'Storage', 'Http', 'Bus', 'Mail', 'Notification'];
    $pattern = '/\b('.implode('|', $facades).')::/';

    $matched = array_filter(
        $files,
        fn (string $f): bool => (bool) preg_match($pattern, file_get_contents($f) ?: '')
    );

    expect($matched)->not->toBeEmpty(
        'Không file nào ngay trong tests/Unit/ khớp bộ dò Facade. Hoặc chúng đã dời chỗ, '
        .'hoặc bộ dò đã mục — đọc lại rào này thay vì tin nó đang canh.'
    );
});
