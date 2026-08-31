<?php

declare(strict_types=1);

/**
 * Hàm khai trong một file `*Test.php` chỉ được gọi TRONG chính file đó.
 *
 * ## Vì sao
 *
 * Pest nạp file test theo testsuite. Chạy tuần tự thì mọi file nằm chung MỘT
 * tiến trình, nên một hàm khai ở `ATest.php` tình cờ vẫn tồn tại lúc `BTest.php`
 * chạy — phụ thuộc **vô hình**, và xanh.
 *
 * `pest --parallel` (bật ở CI từ #2778) chia file cho nhiều tiến trình **theo số
 * nhân**. Tiến trình chạy `BTest.php` có thể chưa bao giờ nạp `ATest.php`, và
 * `B` chết với `Call to undefined function`. Vì cách chia phụ thuộc số nhân, máy
 * dev và runner làm lộ ra những cái KHÁC nhau — nên nó cũng không tái hiện ổn
 * định.
 *
 * Bài này là cặp còn lại của `TestHelperNameCollisionTest`: cái kia cấm hai file
 * khai TRÙNG tên, cái này cấm một file DÙNG tên của file khác.
 *
 * ## Lịch sử — và vì sao bài này bắt đầu ở trạng thái XANH
 *
 * Ca thật duy nhất là `statusValue()`, khai ở `VoidRefundLineIsRefusedTest` và
 * gọi từ `RefundTraceProtectionTest`; #2778/#2780 đã chuyển nó về `tests/Pest.php`.
 *
 * #2784 khai thêm **bảy** ca nữa. Đo lại trên `dev` bằng token PHP: **0/9 cặp**
 * là gọi trần thật — mọi "chỗ gọi thiếu require" hoá ra là comment/docblock,
 * `$this->method()`, hoặc `Class::static()`. Bản sửa dựng theo bảng đó bị trả về
 * (PR #2785) vì nó dời cả `beforeEach()` và 36 `it()` sang file helper, làm 21
 * test đỏ và làm 36 bài kiểm định template biến mất khỏi mọi cổng chọn theo tên.
 *
 * Nên phần còn lại đáng giữ của #2784 chính là bài này: lớp lỗi đã sạch, và rào
 * giữ cho nó sạch.
 *
 * ## Cách sửa khi ĐỎ
 *
 * Đưa helper ra file dùng chung rồi `require_once` nó — khuôn có sẵn:
 * `vpr_helpers.php`, `vst_helpers.php`. Dùng rộng thì đặt ở `tests/Pest.php`
 * (nơi đã có `grantOrgAccess`, `makeFaq`, `statusValue`).
 *
 * **KHÔNG `require_once` thẳng một file test**: file test Pest gọi `it(...)` ở
 * tầng ngoài cùng, nạp nó từ nơi khác sẽ ĐĂNG KÝ LẠI toàn bộ test của nó và quy
 * kết chúng sang file đang nạp — đúng thứ đã làm PR #2785 đỏ.
 */

/**
 * Chỗ gọi hàm `$names` ở dạng GỌI TRẦN — không phải `$this->x()`, `C::x()`,
 * `function x()`, và không nằm trong comment.
 *
 * Dùng token PHP chứ không regex: phép quét sinh ra #2784 là
 * `git grep -lE "\bstatusValue\("` → **0 file**, vì `git grep` không hiểu `\b`;
 * bản không có `\b` ra 2 file. Một bộ dò mù báo "sạch" nghe y hệt sạch thật.
 *
 * @param  array<string, string>  $names  tên hàm → file khai
 * @return array<string, list<int>> tên hàm → các dòng gọi trần
 */
function testHelperBareCalls(string $source, array $names): array
{
    $tokens = token_get_all($source);
    $hits = [];

    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_STRING || ! isset($names[$token[1]])) {
            continue;
        }

        $prev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $prev = $tokens[$j];
            break;
        }

        $next = null;
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            $next = $tokens[$j];
            break;
        }

        if ($next !== '(') {
            continue;
        }

        $prevKind = is_array($prev) ? token_name($prev[0]) : (string) $prev;

        if (in_array($prevKind, ['T_OBJECT_OPERATOR', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_DOUBLE_COLON', 'T_FUNCTION'], true)) {
            continue;
        }

        $hits[$token[1]][] = $token[2];
    }

    return $hits;
}

/**
 * @return list<string>
 */
function testHelperPhpFiles(): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests')));

    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * @return array<string, string> tên hàm → file `*Test.php` khai nó
 */
function testHelperDeclarationsInTestFiles(): array
{
    $declared = [];

    foreach (testHelperPhpFiles() as $file) {
        if (! str_ends_with($file, 'Test.php')) {
            continue;
        }

        // Chỉ khai ở CỘT 0: `function` thụt lề là closure hoặc method, không
        // đụng không gian tên toàn cục.
        preg_match_all('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', (string) file_get_contents($file), $m);

        foreach ($m[1] as $name) {
            $declared[$name] = $file;
        }
    }

    return $declared;
}

it('bộ dò phân biệt được gọi trần với method/static/comment', function () {
    // Rào này tồn tại vì một phép quét MÙ đã báo "sạch". Trước khi tin kết quả
    // rỗng ở bài dưới, chứng minh bộ dò nhìn thấy đúng thứ cần thấy.
    $probe = <<<'PHP'
    <?php
    // makeThing() trong comment — KHÔNG tính
    /** @see makeThing() trong docblock — KHÔNG tính */
    $a = $this->makeThing();
    $b = Helper::makeThing();
    $c = makeThing();
    function makeThing() {}
    PHP;

    $hits = testHelperBareCalls($probe, ['makeThing' => 'x']);

    expect($hits['makeThing'] ?? [])->toHaveCount(
        1,
        'Bộ dò phải bắt ĐÚNG một chỗ gọi trần và bỏ qua comment, $this->, Class:: '
        .'và chính dòng khai. Bắt nhiều hơn ⇒ dương tính giả; bắt ít hơn ⇒ mù.'
    );
});

it('không hàm nào của file test bị file khác gọi trần', function () {
    $declared = testHelperDeclarationsInTestFiles();

    // MẪU SỐ 1 — có hàm nào để kiểm không.
    expect(count($declared))->toBeGreaterThan(
        100,
        'Quét ra quá ít hàm khai trong `*Test.php` — bộ quét hỏng hoặc bố cục '
        .'thư mục đã đổi. Rào không có gì để canh thì phải ĐỎ, không được xanh.'
    );

    $violations = [];
    $bareCallSites = 0;

    foreach (testHelperPhpFiles() as $file) {
        $hits = testHelperBareCalls((string) file_get_contents($file), $declared);

        foreach ($hits as $name => $lines) {
            $bareCallSites += count($lines);

            if ($declared[$name] !== $file) {
                $violations[] = sprintf(
                    '  %s() khai ở %s — gọi trần ở %s (dòng %s)',
                    $name,
                    str_replace(base_path().'/', '', $declared[$name]),
                    str_replace(base_path().'/', '', $file),
                    implode(', ', $lines),
                );
            }
        }
    }

    // MẪU SỐ 2 — có thật sự khớp được chỗ gọi nào không.
    expect($bareCallSites)->toBeGreaterThan(
        1000,
        'Không tìm thấy chỗ gọi trần nào đáng kể. Trên dev đo được 8383 chỗ, nên '
        .'con số ~0 nghĩa là bộ dò mù chứ không phải cây sạch (#2784).'
    );

    sort($violations);

    expect($violations)->toBe([], implode("\n", array_merge(
        ['Hàm khai trong file test bị file KHÁC gọi trần:'],
        $violations,
        [
            '',
            'Chạy tuần tự thì xanh (mọi file chung một tiến trình); `pest --parallel`',
            'chia file theo số nhân nên tiến trình gọi có thể chưa nạp file khai ⇒',
            '`Call to undefined function`.',
            '',
            'Sửa: đưa helper ra `*_helpers.php` cạnh nơi dùng rồi `require_once`, hoặc',
            'vào `tests/Pest.php` nếu dùng rộng. ĐỪNG `require_once` một file test —',
            'nó đăng ký lại toàn bộ test của file đó (PR #2785 đã trả giá).',
        ],
    )));
});
