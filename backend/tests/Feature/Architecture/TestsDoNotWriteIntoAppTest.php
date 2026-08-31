<?php

declare(strict_types=1);

/**
 * #2786 — cấm test ghi/xoá file trong app/ lúc chạy.
 *
 * RawTableReadsTest từng `file_put_contents(app_path(...))`. PhantomColumnsCommand
 * quét app/ rồi file_get_contents từng file. Chạy song song: một process unlink
 * trong khi process kia đang đọc → 22 test đỏ.
 *
 * Bản đầu của rào này dò bằng regex `file_put_contents\s*\([^;]*app_path\s*\(`
 * — đòi hai thứ nằm CÙNG một câu lệnh. Thủ phạm thật gán trước
 * (`$fixture = app_path(...); file_put_contents($fixture, ...)`) nên regex đó
 * trả 0: rào MÙ đúng ca nó sinh ra để chặn, và probe "tôi có mù không" cũng chỉ
 * thử dạng inline nên nó tự cấp chứng chỉ cho chính sự mù đó.
 *
 * Nay dò theo TOKEN (`token_get_all`), không phải regex trên text thô:
 *   1. bỏ comment/docblock — khớp trong comment là bẫy tái diễn ở repo này;
 *   2. lan vết (taint) mọi biến nhận giá trị dẫn xuất từ `app_path()` hoặc
 *      `base_path('app/…')`, lặp tới điểm bất động nên bắc được nhiều chặng;
 *   3. báo động khi BẤT KỲ tham số nào của một lời gọi ghi/xoá file mang vết đó.
 *
 * Rào phải biết KÊU và biết IM: có probe cho cả hai dạng ghi lẫn một mẫu ĐỌC
 * hợp lệ (`file_get_contents(app_path(...))`, hàng trăm chỗ trong tests/ đang
 * dùng) — kêu oan thì rào bị tắt, không phải bị tranh luận.
 */

/**
 * Hàm ghi/xoá theo đường dẫn. Soi MỌI tham số chứ không chỉ tham số đầu:
 * copy/rename/symlink/link nhận đích ở tham số thứ hai.
 *
 * @return list<string>
 */
function appWriteRatchetPathFunctions(): array
{
    return [
        'file_put_contents', 'fopen', 'copy', 'rename', 'touch', 'mkdir', 'rmdir',
        'unlink', 'symlink', 'link', 'chmod', 'chown', 'move_uploaded_file', 'tempnam',
    ];
}

/**
 * Method ghi/xoá của Laravel `File::` / `Storage::` (và biến thể gọi qua `->`).
 *
 * @return list<string>
 */
function appWriteRatchetPathMethods(): array
{
    return [
        'put', 'append', 'prepend', 'replace', 'replaceinfile', 'copy', 'move',
        'delete', 'makedirectory', 'ensuredirectoryexists', 'cleandirectory',
        'deletedirectory', 'copydirectory', 'movedirectory', 'chmod', 'link',
    ];
}

/**
 * Token có ý nghĩa (bỏ comment + whitespace), dạng [type, text, line].
 *
 * @return list<array{0:int,1:string,2:int}>
 */
function appWriteRatchetTokens(string $src): array
{
    $tokens = [];
    foreach (token_get_all($src) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML], true)) {
                continue;
            }
            $tokens[] = [$token[0], $token[1], $token[2]];

            continue;
        }
        $tokens[] = [-1, $token, 0];
    }

    return $tokens;
}

/**
 * Token thứ $i có phải điểm neo "đường dẫn trỏ vào app/" không?
 *
 * @param  list<array{0:int,1:string,2:int}>  $tokens
 */
function appWriteRatchetIsAppPathAnchor(array $tokens, int $i): bool
{
    [$type, $text] = $tokens[$i];
    if ($type !== T_STRING) {
        return false;
    }
    if (strcasecmp($text, 'app_path') === 0) {
        return true;
    }
    if (strcasecmp($text, 'base_path') === 0) {
        $open = $tokens[$i + 1] ?? null;
        $arg = $tokens[$i + 2] ?? null;
        if ($open !== null && $open[1] === '(' && $arg !== null && $arg[0] === T_CONSTANT_ENCAPSED_STRING) {
            return (bool) preg_match('#^([\'"])app(/|\\1)#', $arg[1]);
        }
    }

    return false;
}

/**
 * Danh sách "dòng:tên lời gọi" ghi/xoá vào app/. Rỗng = sạch.
 *
 * @return list<string>
 */
function appWriteRatchetHits(string $src): array
{
    $tokens = appWriteRatchetTokens($src);
    $count = count($tokens);

    // (2) lan vết biến mang app_path(), lặp tới điểm bất động (tối đa 4 chặng).
    $tainted = [];
    for ($round = 0; $round < 4; $round++) {
        $changed = false;
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_VARIABLE) {
                continue;
            }
            $operator = $tokens[$i + 1] ?? null;
            if ($operator === null || ! in_array($operator[1], ['=', '.=', '??='], true)) {
                continue;
            }
            $name = $tokens[$i][1];
            if (isset($tainted[$name])) {
                continue;
            }
            $depth = 0;
            for ($j = $i + 2; $j < $count; $j++) {
                $text = $tokens[$j][1];
                if ($text === '(' || $text === '[') {
                    $depth++;
                }
                if ($text === ')' || $text === ']') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                }
                if ($text === ';' && $depth === 0) {
                    break;
                }
                if (appWriteRatchetIsAppPathAnchor($tokens, $j)
                    || ($tokens[$j][0] === T_VARIABLE && isset($tainted[$tokens[$j][1]]))) {
                    $tainted[$name] = true;
                    $changed = true;
                    break;
                }
            }
        }
        if (! $changed) {
            break;
        }
    }

    // (3) lời gọi ghi/xoá có tham số dính vết.
    $functions = appWriteRatchetPathFunctions();
    $methods = appWriteRatchetPathMethods();
    $hits = [];
    for ($i = 0; $i < $count; $i++) {
        [$type, $text, $line] = $tokens[$i];
        if ($type !== T_STRING) {
            continue;
        }
        $previous = $tokens[$i - 1][1] ?? '';
        $lowered = strtolower($text);
        $isWrite = in_array($lowered, $functions, true)
            ? ! in_array($previous, ['->', '::', '?->', 'function'], true)
            : (in_array($lowered, $methods, true) && in_array($previous, ['::', '->', '?->'], true));
        if (! $isWrite) {
            continue;
        }
        $open = $tokens[$i + 1] ?? null;
        if ($open === null || $open[1] !== '(') {
            continue;
        }
        $depth = 0;
        for ($j = $i + 1; $j < $count; $j++) {
            $argument = $tokens[$j][1];
            if ($argument === '(') {
                $depth++;

                continue;
            }
            if ($argument === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }

                continue;
            }
            if (appWriteRatchetIsAppPathAnchor($tokens, $j)
                || ($tokens[$j][0] === T_VARIABLE && isset($tainted[$tokens[$j][1]]))) {
                $hits[] = $line.':'.$text;
                break;
            }
        }
    }

    return $hits;
}

function writesIntoAppSource(string $src): bool
{
    return appWriteRatchetHits($src) !== [];
}

it('bộ dò bắt dạng INLINE — file_put_contents(app_path(...))', function () {
    $probe = <<<'PHP'
    <?php
    file_put_contents(app_path('Services/Order/Internal/X.php'), 'x');
    PHP;

    expect(writesIntoAppSource($probe))->toBeTrue(
        'bộ dò phải khớp dạng inline. Nới pattern mà bài này vẫn xanh = rào mù.',
    );
});

it('bộ dò bắt dạng GÁN TRƯỚC — nguyên văn thủ phạm #2786', function () {
    // Nguyên văn origin/dev:backend/tests/Feature/Architecture/RawTableReadsTest.php:56-82
    // (ca đã thật sự làm 22 test đỏ). Regex bản đầu trả 0 trên đúng đoạn này.
    $offender = "<?php\n".<<<'OFFENDER'
it('bỏ comment và docblock nhưng vẫn giữ lời gọi đọc bảng thật', function () {
    $fixture = app_path('Services/Order/Internal/RawTableReadsCommentFixture'.getmypid().'.php');
    file_put_contents($fixture, <<<'PHP'
<?php
namespace App\Services\Order\Internal;

/** Trước đây từng gọi DB::table('product_category') ở đây. */
final class RawTableReadsCommentFixture
{
    public function read(): void
    {
        DB::table('product_category')->first();
    }
}
PHP);
    try {
        $rows = array_values(array_filter(
            rawTableReadReport()['cross_module'],
            static fn (array $row): bool => basename($row['file']) === basename($fixture),
        ));
        expect($rows)->toHaveCount(1, 'docblock phải bị bỏ, còn lời gọi thật vẫn phải được đếm')
            ->and($rows[0]['table'])->toBe('product_category');
    } finally {
        @unlink($fixture);
    }
});
OFFENDER;

    // Regex bản đầu — giữ lại để chứng minh lỗ, không phải để dùng.
    $blindPattern = '/file_put_contents\s*\([^;]*app_path\s*\(/s';
    expect(preg_match($blindPattern, $offender))->toBe(
        0,
        'nếu dạng gán-trước bỗng khớp regex cũ thì probe này không còn chứng minh gì — thay bằng thủ phạm thật khác.',
    );

    expect(writesIntoAppSource($offender))->toBeTrue(
        'bộ dò phải bắt dạng `$f = app_path(...); file_put_contents($f, ...)` — chính ca #2786.',
    );
});

it('bộ dò biết IM — đọc app/ là hợp lệ, hàng trăm test đang làm', function () {
    $reader = <<<'PHP'
    <?php
    $source = (string) file_get_contents(app_path('Services/Order/OrderService.php'));
    foreach (glob(app_path('Models/*.php')) as $model) {
        $files[] = str_replace(app_path().'/', '', $model);
    }
    PHP;

    expect(writesIntoAppSource($reader))->toBeFalse(
        'rào kêu oan thì bị TẮT. Đọc app/ không phải vi phạm — chỉ ghi/xoá mới là.',
    );
});

it('không test nào ghi file vào app/ lúc chạy', function () {
    $hits = [];
    $scanned = 0;
    $root = base_path('tests');
    $self = realpath(__FILE__);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (realpath($path) === $self) {
            continue;
        }
        $scanned++;
        $src = (string) file_get_contents($path);
        $found = appWriteRatchetHits($src);
        if ($found !== []) {
            $hits[] = str_replace(base_path().'/', '', $path).' ('.implode(', ', $found).')';
        }
    }

    // Mẫu số: bộ quét hỏng thì 0 file → danh sách rỗng đọc y hệt "sạch".
    expect($scanned)->toBeGreaterThan(
        500,
        sprintf('chỉ quét được %d file test — bộ quét hỏng, không phải repo sạch.', $scanned),
    );

    expect($hits)->toBeEmpty(
        'Test ghi vào app/: '.implode(', ', $hits).'. Đưa fixture ra sys_get_temp_dir() và --path.',
    );
});
