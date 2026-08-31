<?php

declare(strict_types=1);

/**
 * #1091 §4.4 — architecture guard: business-time decisions must go through
 * \App\Support\BusinessClock (branch timezone), never the naive app clock.
 *
 * Banned in app/Services/** and app/Http/Controllers/**:
 *   now()->toDateString()   — "today" in the APP timezone, not the branch's
 *   Carbon::today()         — argless only; Carbon::today($branchTz) is the
 *                             CORRECT per-branch pattern and stays allowed
 *   ->whereDate(            — compares against the DB session's (UTC) date
 *
 * Existing offenders are GRANDFATHERED with their exact violation count.
 * The list is shrink-only: fixing a file lowers (or removes) its entry; a new
 * violation — new file or a higher count in a listed file — fails this test.
 * Same model as the DomainMutationGuard allowlist.
 */
const BUSINESS_TIME_BANNED_PATTERN = '/now\(\)->toDateString\(\)|Carbon::today\(\)|->whereDate\(/';

/**
 * file (relative to base_path) => grandfathered violation count (2026-07-26).
 * Do NOT add entries — route new code through BusinessClock (#1091).
 */
const BUSINESS_TIME_GRANDFATHERED = [
    // 2026-07-27 (#1091): the debt is fully paid — Inventory cluster and the
    // CustomerOrder pair all route through
    // BusinessClock::utcRangeForBusinessDates. Keep this list EMPTY.
];

/**
 * Chỉ giữ phần MÃ, bỏ comment và docblock (#1921, cùng bệnh với #1822).
 *
 * Trước bản này scanner chạy `preg_match_all` trên nội dung thô, nên một dòng
 * giải thích kiểu *"trước đây chỗ này là `now()->toDateString()`, xem #1091"*
 * làm cổng báo vi phạm — tức **việc ghi lại một lỗi đã sửa bị tính là lỗi**.
 *
 * Đó là loại rào tệ hơn không có rào: nó phạt đúng người đang viết lời giải
 * thích cho người sau, và cách rẻ nhất để qua nó là ĐỪNG giải thích. `#1822` đã
 * gặp y hệt ở `LegacyRemovalReadiness::codePresent()` và chữa bằng cùng cách —
 * PHP đã tách sẵn comment thành `T_COMMENT` / `T_DOC_COMMENT`.
 *
 * Waiver nội tuyến `#1091-ok` vẫn hoạt động như cũ: nó là comment, nên nó
 * không bao giờ nằm trong phần mã mà hàm này trả về.
 */
function businessTimeCodeOnly(string $contents): string
{
    $out = '';

    foreach (@token_get_all($contents) as $token) {
        if (! is_array($token)) {
            $out .= $token;

            continue;
        }

        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
            continue;
        }

        $out .= $token[1];
    }

    return $out;
}

/**
 * Cùng cơ chế `token_get_all` như trên, nhưng GIỮ NGUYÊN SỐ DÒNG: mỗi token bị
 * loại được thay bằng đúng số xuống dòng nó chiếm, nên `file:line` báo ra vẫn
 * trỏ đúng chỗ trong file gốc.
 *
 * Vì sao cần: các cổng quét theo DÒNG bên dưới trước đây chỉ bỏ được dòng
 * comment THUẦN (`//`, `*`, `/*`). Một comment ĐUÔI DÒNG — `$x = 1; // đừng
 * dùng CURRENT_DATE` — vẫn bị tính là vi phạm, tức lại đúng cái bệnh #1921 đã
 * chữa cho cổng đầu tiên. Tách bằng tokenizer thì cả hai kiểu comment biến mất
 * một lần cho cả ba cổng.
 *
 * `$blankStrings` chỉ dành cho cổng ISO-8601: ở đó nội dung chuỗi là văn xuôi
 * (thông điệp lỗi, tên cột) chứ không phải phép so, nên làm rỗng nó cắt sạch ca
 * khớp-nhầm-trong-string-literal. Hai cổng còn lại KHÔNG được dùng cờ này —
 * `CURDATE`/`CURRENT_DATE` sống trong chuỗi SQL thô, làm rỗng chuỗi là tắt tiếng
 * chính chúng. Ngoại lệ duy nhất được giữ lại là literal `'c'`: nó không phải
 * văn xuôi mà là MÃ ĐỊNH DẠNG ISO-8601 của `format()`.
 *
 * @return array<int, string> các dòng CHỈ-CÒN-MÃ, cùng số lượng với file gốc
 */
function businessTimeCodeLines(string $contents, bool $blankStrings = false): array
{
    $out = '';

    foreach (@token_get_all($contents) as $token) {
        if (! is_array($token)) {
            $out .= $token;

            continue;
        }

        [$id, $text] = $token;

        $isComment = $id === T_COMMENT || $id === T_DOC_COMMENT;
        $isProseString = $blankStrings
            && ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE)
            && trim($text, '\'"') !== 'c';

        $out .= $isComment || $isProseString
            ? str_repeat("\n", substr_count($text, "\n"))
            : $text;
    }

    return explode("\n", $out);
}

it('blocks new naive business-date usage outside BusinessClock (#1091)', function () {
    $roots = [base_path('app/Services'), base_path('app/Http/Controllers')];

    $violations = [];
    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            $count = preg_match_all(BUSINESS_TIME_BANNED_PATTERN, businessTimeCodeOnly($contents));
            if ($count > 0) {
                $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
                $violations[$relative] = $count;
            }
        }
    }

    $newOffenders = [];
    foreach ($violations as $file => $count) {
        $allowed = BUSINESS_TIME_GRANDFATHERED[$file] ?? 0;
        if ($count > $allowed) {
            $newOffenders[] = "{$file}: {$count} (grandfathered: {$allowed})";
        }
    }

    expect($newOffenders)->toBe([], implode("\n", [
        'New naive business-date usage detected. Use \App\Support\BusinessClock',
        '(branch timezone) instead of the app clock — see docs/guide/business-time.md (#1091).',
        ...$newOffenders,
    ]));

    // Shrink-only bookkeeping: when a grandfathered file is fixed, remove or
    // lower its entry so the debt cannot silently grow back.
    $stale = [];
    foreach (BUSINESS_TIME_GRANDFATHERED as $file => $allowed) {
        $actual = $violations[$file] ?? 0;
        if ($actual < $allowed) {
            $stale[] = "{$file}: now {$actual}, allowlisted {$allowed} — lower the entry";
        }
    }

    expect($stale)->toBe([], "Grandfathered business-time entries are stale:\n".implode("\n", $stale));
});

/*
 * Three more ways to leak a non-branch clock into a business decision, added
 * alongside the naive-app-clock guard above rather than as a second scanner —
 * one guard for one rule, so nobody has to know which file to look in.
 *
 * These scan LINE BY LINE over the CODE-ONLY view of the file (comments and
 * docblocks blanked by `businessTimeCodeLines`, line numbers intact), because
 * every pattern here is legitimately named in prose: a docblock warning "never
 * use CURRENT_TIME" must not be read as using it.
 */

/** @return array<int, string> "file:line  snippet" for each match */
function scanBusinessTimeLines(string $pattern, bool $blankStrings = false): array
{
    $findings = [];

    foreach ([base_path('app/Services'), base_path('app/Http/Controllers')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
            $contents = (string) file_get_contents($file->getPathname());
            $rawLines = explode("\n", $contents);

            foreach (businessTimeCodeLines($contents, $blankStrings) as $i => $line) {
                // An inline waiver must name the issue so a reviewer can judge
                // it — and it lives in a comment, so read it off the RAW line.
                if (str_contains($rawLines[$i] ?? '', '#1091-ok')) {
                    continue;
                }
                if (preg_match($pattern, $line) === 1) {
                    $findings[] = $relative.':'.($i + 1).'  '.trim($rawLines[$i] ?? $line);
                }
            }
        }
    }

    return $findings;
}

it('never lets the DATABASE decide a business day (#1091)', function () {
    // The DB session runs in its own timezone and Carbon::setTestNow() never
    // reaches it, so these are untestable as well as wrong.
    $findings = scanBusinessTimeLines('/\b(CURDATE\s*\(|CURRENT_DATE\b|CURRENT_TIME\b)/i');

    expect($findings)->toBe([], implode("\n", [
        "SQL's own clock cannot know a shop's business day. Compute the range with",
        'BusinessClock::utcRangeForBusinessDates() and compare instants, or append `#1091-ok`:',
        ...$findings,
    ]));
});

/**
 * #2708 — chuỗi ISO-8601 KHÔNG được đi vào một phép so với cột datetime.
 *
 * Ở tầng SQL cả hai đều là chuỗi, nên phép so là so từng KÝ TỰ, và ký tự thứ 11
 * quyết định tất cả trước khi tới phần giờ:
 *
 *     cột  : '2026-08-12 21:56:13'
 *     mốc  : '2026-08-12T21:51:13+00:00'
 *                       ↑ ' ' (0x20) < 'T' (0x54)
 *
 * Hạt lỗi là NGÀY: hàng khác ngày vẫn lọc đúng, chỉ hàng CÙNG NGÀY với mốc rơi
 * sai phía — nên bộ lọc ranh ca "lọt tất cả" mà không lỗi, không cảnh báo. Và nó
 * PHỤ THUỘC ENGINE: SQLite (engine test) so chuỗi thật, MySQL 8 (production)
 * coerce hằng về temporal nên không tái hiện. Không test nghiệp vụ nào bắt được
 * — chỉ rào tĩnh này.
 *
 * Cách viết đúng: truyền thẳng `Carbon`/`DateTimeInterface` và để binder lo, hoặc
 * `->format('Y-m-d H:i:s')`.
 *
 * Rào CỐ Ý HẸP, vì trong hai thư mục này có HÀNG TRĂM chỗ gọi `toIso8601String()`
 * hoàn toàn hợp lệ — serialize ra JSON response (đo 2026-08-13: 156) — và một
 * rào kêu oan là một rào sẽ bị tắt. Hai lằn ranh:
 *
 * 1. Hàm sinh ISO phải nằm TRONG cặp ngoặc của chính lời gọi `where`-family.
 *    Nhóm `(?&paren)` đệ quy nuốt các nhóm ngoặc CÂN BẰNG, nên biểu thức không
 *    thể vượt qua dấu `)` đóng lời gọi đó. Nhờ vậy chuỗi hợp lệ
 *    `->where(...)->get()->map(fn ($r) => $r->created_at->toIso8601String())`
 *    KHÔNG bị bắt, dù đứng cùng dòng.
 * 2. Cả hai phải cùng một dòng MÃ. Gán chuỗi vào biến ở dòng trước rồi mới
 *    `where($var)` thì lọt — thà hẹp còn hơn oan.
 */
const BUSINESS_TIME_ISO_COMPARISON_PATTERN = '/'.
    '(?(DEFINE)(?<paren>\((?:[^()]++|(?&paren))*+\)))'.
    '\b(?:where|orWhere|whereBetween|orWhereBetween|whereNotBetween|orWhereNotBetween'.
    '|having|orHaving|havingBetween|whereRaw|orWhereRaw|havingRaw)\s*\('.
    '(?:[^();]|(?&paren))*?'.
    '\??->\s*(?:'.
    'to(?:Iso8601|Iso8601Zulu|ISO|Atom|Rfc3339|W3c)String\s*\(\s*\)'.
    '|format\s*\(\s*(?:DATE_ATOM|DATE_RFC3339|DATE_W3C|[\'"]c[\'"])\s*\)'.
    ')/';

it('never compares an ISO-8601 string against a datetime column (#2708)', function () {
    $findings = scanBusinessTimeLines(BUSINESS_TIME_ISO_COMPARISON_PATTERN, blankStrings: true);

    expect($findings)->toBe([], implode("\n", [
        'An ISO-8601 string compared to a datetime column is a CHARACTER comparison:',
        "the 'T' at position 11 decides it before the clock does, so same-day rows fall",
        'on the wrong side — silently, and only under SQLite. Pass the Carbon instance',
        "and let the binder format it (or ->format('Y-m-d H:i:s')), or append `#1091-ok`:",
        ...$findings,
    ]));
});

it('keeps the display-timezone middleware out of business logic (#1091)', function () {
    // SetTimezone resolves the VIEWER's timezone. Using it for a business
    // decision means two colleagues in different countries see different
    // business days for the same shift.
    $findings = scanBusinessTimeLines('/SetTimezone::ATTRIBUTE/');

    expect($findings)->toBe([], implode("\n", [
        "SetTimezone is the VIEWER's display timezone, not the shop's.",
        'Use BusinessClock (branch timezone) for the decision and convert only for display:',
        ...$findings,
    ]));
});
