<?php

declare(strict_types=1);

/**
 * Không hai file test nào được khai cùng một hàm TOÀN CỤC.
 *
 * ## Vì sao cần rào, khi PHP đã tự báo lỗi
 *
 * Vì cách nó báo là kiểu tệ nhất. Pest nạp MỌI file test vào MỘT tiến trình, nên
 * hai `function makeOrder()` cho ra `Cannot redeclare function` — và đó là
 * **fatal lúc nạp**, không phải một test đỏ.
 *
 * Hệ quả: `pest --list-tests-xml` chết, tức `backend/bin/full-suite` không liệt
 * kê nổi test và dừng ngay ở bước đầu. Cả cổng dev→main không chạy được, vì một
 * cái tên hàm.
 *
 * Và nó ẩn được rất lâu: chạy từng file thì xanh, chạy từng thư mục cũng xanh,
 * chỉ chết khi nạp cả hai file cùng lúc. Đúng như đã xảy ra — `makeOrder` vào
 * repo ở #1994 và không ai thấy cho tới lượt full-suite kế tiếp, vì mọi lượt
 * chạy ở giữa đều là chạy tập con.
 *
 * ## Repo VỐN có kỷ luật này, chỉ là không cưỡng chế
 *
 * `makeOrderForSku`, `makeOrderViaService`, `makeOrderWithVoidedItem`,
 * `makeOrderForScenario`, `makeOrderWithItems` — năm helper cùng họ, năm tên
 * riêng biệt. Người viết chúng đã biết luật. Bài này chỉ biến nó từ thói quen
 * thành thứ máy kiểm.
 *
 * ## Cách sửa khi đỏ
 *
 * Đặt tên riêng cho helper (`makeOverCollectedOrder`), hoặc nếu hai chỗ thật sự
 * cần dùng chung thì đưa nó vào `tests/Pest.php` — nơi đã có `grantOrgAccess`
 * và `makeFaq` với đúng lý do đó.
 */
it('không hai file test nào khai trùng một hàm toàn cục', function () {
    /** @var array<string, list<string>> $seen */
    $seen = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests')));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        // Chỉ khai ở CỘT 0: một `function` thụt lề là closure hoặc method, và
        // cả hai đều không đụng không gian tên toàn cục.
        preg_match_all('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $source, $matches);

        foreach ($matches[1] as $name) {
            $seen[$name][] = $relative;
        }
    }

    $collisions = [];
    foreach ($seen as $name => $files) {
        $files = array_values(array_unique($files));
        if (count($files) > 1) {
            sort($files);
            $collisions[$name] = $files;
        }
    }

    ksort($collisions);

    expect($collisions)->toBe([], implode("\n", array_merge(
        ['Hàm toàn cục bị khai ở nhiều file test:'],
        array_map(
            static fn ($n, $f) => "  {$n}() ← ".implode(', ', $f),
            array_keys($collisions),
            $collisions,
        ),
        [
            '',
            'Pest nạp mọi file test vào MỘT tiến trình, nên đây là fatal lúc nạp —',
            '`pest --list-tests-xml` chết và `bin/full-suite` dừng ở bước đầu.',
            '',
            'Sửa: đặt tên riêng cho helper, hoặc đưa nó vào tests/Pest.php nếu hai',
            'chỗ thật sự cần dùng chung.',
        ],
    )));
});
