<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 M5 (#1171) — printer geometry, ported from
 * `workstation/internal/service/print_service.go`.
 *
 * These functions decide where every character lands, so they are the part of
 * the port most likely to fail silently: an off-by-one in `displayWidth` moves
 * a price by one column on every line of every receipt and still "looks fine"
 * in a diff of the text.
 *
 * Two measurements coexist ON PURPOSE. `displayWidth` counts printer COLUMNS
 * (a Kanji glyph is two); `runeLength` counts CODE POINTS. The VAT invoice and
 * the debt slip were laid out with rune counting and their column positions are
 * baked into slips shops have already filed, so the port keeps each document's
 * own arithmetic rather than "fixing" one of them mid-migration (TR-40).
 */
final class Layout
{
    /**
     * #2035 — NARROW PAPER (58mm).
     *
     * Every slip in this namespace was laid out against 42 or 48 content
     * columns: 42 is what the LAN print handlers send, 48 is the `defaultWidth`
     * of every kind. 32 columns (58mm roll) has been reachable through
     * configuration the whole time and was never measured, so three blocks emit
     * lines wider than the paper there — the gap arithmetic floors at one column
     * and then prints a line the printer wraps mid-figure.
     *
     * The fix is per-block narrow VARIANTS, gated on this threshold rather than
     * on "does it fit". The gate is deliberately blunt:
     *
     *  - The 42- and 48-column slips shops print today must not change by one
     *    byte. A fit-based trigger relies on measuring every possible line to
     *    prove that; a width gate makes the narrow branch UNREACHABLE from those
     *    widths, which is the same claim without the measurement.
     *  - It keeps the two repos honest. PHP and Go must agree byte-for-byte
     *    (`SlipByteParityTest`, G3), and one shared integer comparison is far
     *    easier to keep in step than two drifting fit heuristics.
     *
     * Being under the threshold is necessary, not sufficient: each variant still
     * checks that the normal form actually fails to fit, so a short 32-column
     * line keeps its one-line shape. Counterpart of `printNarrowColumns` /
     * `isNarrowSlip` in `workstation/internal/service/print_narrow.go`.
     */
    public const NARROW_COLUMNS = 42;

    /**
     * Whether `$columns` is below the width the slips were designed against —
     * i.e. whether the narrow variants are allowed at all.
     */
    public static function isNarrow(int $columns): bool
    {
        return $columns < self::NARROW_COLUMNS;
    }

    /** Câu miễn trừ pháp lý, nguyên văn khi vừa giấy. */
    public const VAT_DISCLAIMER = 'KHONG THAY THE HDDT CUA CO QUAN THUE';

    /** Nửa đầu ở khổ hẹp — ngắt theo cụm nghĩa, xem {@see self::vatDisclaimerLines}. */
    public const VAT_DISCLAIMER_NARROW_A = 'KHONG THAY THE HDDT';

    /** Nửa sau ở khổ hẹp. */
    public const VAT_DISCLAIMER_NARROW_B = 'CUA CO QUAN THUE';

    /**
     * Đối ứng của `vatDisclaimerLines` bên Go (`print_narrow.go`).
     *
     * Câu này rộng 36 cột, không vừa giấy 58mm, và nó là dòng DUY NHẤT trên tờ
     * giấy không được phép mất chữ: nó nói rằng tờ này KHÔNG thay thế hoá đơn
     * điện tử của cơ quan thuế. Nên ở khổ hẹp nó ngắt theo cụm nghĩa chứ không
     * rút gọn.
     *
     * Ngắt bằng hằng số viết sẵn chứ không gọi {@see self::wrapText}: bộ ngắt
     * tham lam đẩy mỗi chữ "THUE" xuống dòng hai của một khối canh giữa. Chỗ ngắt
     * ở đây là quyết định về cách đọc, không phải phép tính. Ba hằng số là ba lát
     * của cùng một câu, nên sửa chữ mà quên một cái thì cổng parity nói ngay.
     *
     * ── #2062: vì sao nó SỐNG Ở ĐÂY chứ không ở một họ kind ────────────────
     *
     * Trước #2062 ba hằng này là `private` trong {@see DocsKindPlans} — họ chứng
     * từ — vì chỉ `vat_invoice` in chúng. `red_invoice` thuộc họ BILL
     * ({@see BillKindPlans}), một file khác, nên nó không với tới được. Chép câu
     * sang file thứ hai là cách một lời phủ nhận pháp lý tồn tại ở hai bản và
     * chỉ một bản được sửa. Go đã đặt chúng ở `print_narrow.go` — dùng chung cho
     * mọi họ — nên đưa về `Layout` là làm hai repo giống nhau, không phải bịa ra
     * một chỗ mới.
     *
     * @return list<string>
     */
    public static function vatDisclaimerLines(int $width): array
    {
        if (! self::isNarrow($width) || self::displayWidth(self::VAT_DISCLAIMER) <= $width) {
            return [self::VAT_DISCLAIMER];
        }

        return [self::VAT_DISCLAIMER_NARROW_A, self::VAT_DISCLAIMER_NARROW_B];
    }

    /**
     * Code points the Shift_JIS encoder emits as TWO bytes — therefore two
     * printer columns — that fall outside the CJK blocks {@see self::charWidth}
     * tests. These are the non-kanji rows of JIS X 0208 plus the NEC row-13
     * extensions: symbols, Greek, Cyrillic, box drawing, circled digits, Roman
     * numerals.
     *
     * MUST stay byte-identical to `shiftJISWideRanges` in the workstation's
     * `print_service.go`. That copy is tied to the real encoder by
     * `TestRuneDisplayWidth_MatchesShiftJISEncoder`; this one is a port, and
     * the primitives parity fixture is what proves the port has not drifted.
     *
     * Do NOT regenerate this list from PHP's mbstring: `SJIS-win` is CP932 and
     * encodes a wider set than Go's `japanese.ShiftJIS`, so a table built here
     * would claim columns the paper does not have.
     *
     * Sorted and non-overlapping — {@see self::inShiftJISWideRange} binary-searches it.
     *
     * @var list<array{int, int}>
     */
    private const SHIFT_JIS_WIDE_RANGES = [
        [0x00A7, 0x00A8], // §¨
        [0x00B0, 0x00B1], // °±
        [0x00B4, 0x00B4], // ´
        [0x00B6, 0x00B6], // ¶
        [0x00D7, 0x00D7], // ×
        [0x00F7, 0x00F7], // ÷
        [0x0391, 0x03A1], // ΑΒΓΔΕΖ…
        [0x03A3, 0x03A9], // ΣΤΥΦΧΨ…
        [0x03B1, 0x03C1], // αβγδεζ…
        [0x03C3, 0x03C9], // στυφχψ…
        [0x0401, 0x0401], // Ё
        [0x0410, 0x044F], // АБВГДЕ…
        [0x0451, 0x0451], // ё
        [0x2010, 0x2010], // ‐
        [0x2015, 0x2015], // ―
        [0x2018, 0x2019], // ‘’
        [0x201C, 0x201D], // “”
        [0x2020, 0x2021], // †‡
        [0x2025, 0x2026], // ‥…
        [0x2030, 0x2030], // ‰
        [0x2032, 0x2033], // ′″
        [0x203B, 0x203B], // ※
        [0x2103, 0x2103], // ℃
        [0x2116, 0x2116], // №
        [0x2121, 0x2121], // ℡
        [0x212B, 0x212B], // Å
        [0x2160, 0x2169], // ⅠⅡⅢⅣⅤⅥ…
        [0x2170, 0x2179], // ⅰⅱⅲⅳⅴⅵ…
        [0x2190, 0x2193], // ←↑→↓
        [0x21D2, 0x21D2], // ⇒
        [0x21D4, 0x21D4], // ⇔
        [0x2200, 0x2200], // ∀
        [0x2202, 0x2203], // ∂∃
        [0x2207, 0x2208], // ∇∈
        [0x220B, 0x220B], // ∋
        [0x2211, 0x2211], // ∑
        [0x221A, 0x221A], // √
        [0x221D, 0x2220], // ∝∞∟∠
        [0x2225, 0x2225], // ∥
        [0x2227, 0x222C], // ∧∨∩∪∫∬
        [0x222E, 0x222E], // ∮
        [0x2234, 0x2235], // ∴∵
        [0x223D, 0x223D], // ∽
        [0x2252, 0x2252], // ≒
        [0x2260, 0x2261], // ≠≡
        [0x2266, 0x2267], // ≦≧
        [0x226A, 0x226B], // ≪≫
        [0x2282, 0x2283], // ⊂⊃
        [0x2286, 0x2287], // ⊆⊇
        [0x22A5, 0x22A5], // ⊥
        [0x22BF, 0x22BF], // ⊿
        [0x2312, 0x2312], // ⌒
        [0x2460, 0x2473], // ①②③④⑤⑥…
        [0x2500, 0x2503], // ─━│┃
        [0x250C, 0x250C], // ┌
        [0x250F, 0x2510], // ┏┐
        [0x2513, 0x2514], // ┓└
        [0x2517, 0x2518], // ┗┘
        [0x251B, 0x251D], // ┛├┝
        [0x2520, 0x2520], // ┠
        [0x2523, 0x2525], // ┣┤┥
        [0x2528, 0x2528], // ┨
        [0x252B, 0x252C], // ┫┬
        [0x252F, 0x2530], // ┯┰
        [0x2533, 0x2534], // ┳┴
        [0x2537, 0x2538], // ┷┸
        [0x253B, 0x253C], // ┻┼
        [0x253F, 0x253F], // ┿
        [0x2542, 0x2542], // ╂
        [0x254B, 0x254B], // ╋
        [0x25A0, 0x25A1], // ■□
        [0x25B2, 0x25B3], // ▲△
        [0x25BC, 0x25BD], // ▼▽
        [0x25C6, 0x25C7], // ◆◇
        [0x25CB, 0x25CB], // ○
        [0x25CE, 0x25CF], // ◎●
        [0x25EF, 0x25EF], // ◯
        [0x2605, 0x2606], // ★☆
        [0x2640, 0x2640], // ♀
        [0x2642, 0x2642], // ♂
        [0x266A, 0x266A], // ♪
        [0x266D, 0x266D], // ♭
        [0x266F, 0x266F], // ♯
    ];

    /** Whether the code point is one of the double-byte JIS X 0208 symbols. */
    private static function inShiftJISWideRange(int $r): bool
    {
        $lo = 0;
        $hi = count(self::SHIFT_JIS_WIDE_RANGES) - 1;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            [$from, $to] = self::SHIFT_JIS_WIDE_RANGES[$mid];

            if ($r < $from) {
                $hi = $mid - 1;
            } elseif ($r > $to) {
                $lo = $mid + 1;
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * Column width of a single code point.
     *
     * Combining marks measure ZERO: without that, a decomposed (NFD)
     * Vietnamese name like "Chi nhánh Hà Nội" measures 20 columns instead of
     * 16 and wrongly pushes the branch name onto its own line.
     *
     * ※ (U+203B) and its neighbours in {@see self::SHIFT_JIS_WIDE_RANGES}
     * measure TWO. Unicode calls them East-Asian AMBIGUOUS and they look narrow
     * in every editor, but Shift_JIS emits them as two bytes and the head puts
     * down two columns. Measuring them at one was what left every 軽減税率 item
     * line a column narrow, with its price hanging right of the money column.
     */
    public static function charWidth(string $char): int
    {
        if (preg_match('/^[\p{Mn}\p{Me}]$/u', $char) === 1) {
            return 0;
        }

        $r = mb_ord($char, 'UTF-8');
        if ($r === false) {
            return 1;
        }

        // Tested BEFORE the block list below, because most of these sit under
        // U+1100 — which that list short-circuits as narrow.
        if (self::inShiftJISWideRange($r)) {
            return 2;
        }

        if ($r < 0x1100) {
            return 1;
        }

        $wide = $r <= 0x115F                       // Hangul Jamo
            || $r === 0x2329 || $r === 0x232A
            || ($r >= 0x2E80 && $r <= 0x303E)      // CJK Radicals / Kangxi
            || ($r >= 0x3040 && $r <= 0x33FF)      // Hiragana / Katakana / CJK compat
            || ($r >= 0x3400 && $r <= 0x4DBF)      // CJK Ext-A
            || ($r >= 0x4E00 && $r <= 0x9FFF)      // CJK Unified
            || ($r >= 0xA000 && $r <= 0xA4CF)      // Yi
            || ($r >= 0xAC00 && $r <= 0xD7AF)      // Hangul Syllables
            || ($r >= 0xF900 && $r <= 0xFAFF)      // CJK compat ideographs
            || ($r >= 0xFE10 && $r <= 0xFE19)
            || ($r >= 0xFE30 && $r <= 0xFE6F)      // CJK compat forms
            || ($r >= 0xFF00 && $r <= 0xFF60)      // Fullwidth forms
            || ($r >= 0xFFE0 && $r <= 0xFFE6)
            || ($r >= 0x1F300 && $r <= 0x1F64F)    // Emoji
            || ($r >= 0x20000 && $r <= 0x2FFFD)
            || ($r >= 0x30000 && $r <= 0x3FFFD);

        return $wide ? 2 : 1;
    }

    /** Total printer column width of a string. */
    public static function displayWidth(string $s): int
    {
        $w = 0;
        foreach (self::chars($s) as $ch) {
            $w += self::charWidth($ch);
        }

        return $w;
    }

    /** Code-point count — the VAT invoice / debt slip measurement. */
    public static function runeLength(string $s): int
    {
        return mb_strlen($s, 'UTF-8');
    }

    /** @return list<string> */
    public static function chars(string $s): array
    {
        if ($s === '') {
            return [];
        }

        return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function spaces(int $n): string
    {
        return $n <= 0 ? '' : str_repeat(' ', $n);
    }

    /** Pad to `width` DISPLAY columns; an already-too-wide string is returned as-is. */
    public static function padRight(string $s, int $width): string
    {
        $dw = self::displayWidth($s);

        return $dw >= $width ? $s : $s.self::spaces($width - $dw);
    }

    /**
     * Pad to `width` DISPLAY columns on the LEFT — the right-aligned twin of
     * {@see padRight}.
     *
     * Cột số của bảng hàng trên hoá đơn GTGT căn phải bằng hàm này (SL 3 · Đơn
     * giá 11 · Thành tiền 11). Nó đo bằng CỘT chứ không phải mã điểm, kể cả
     * trong một tờ giấy mà phần còn lại đo bằng mã điểm — chép đúng `padLeft`
     * bên Go, vì tiền là chuỗi ASCII nên hai phép đo trùng nhau ở đó và chỉ
     * tách nhau nếu ai đó đưa chữ vào cột số.
     */
    public static function padLeft(string $s, int $width): string
    {
        $dw = self::displayWidth($s);

        return $dw >= $width ? $s : self::spaces($width - $dw).$s;
    }

    /** "- - - - - -" separator, trimmed to exactly w columns. */
    public static function dashedLine(int $w): string
    {
        if ($w <= 0) {
            return '';
        }
        $s = str_repeat('- ', $w);

        return mb_substr($s, 0, $w, 'UTF-8');
    }

    /**
     * Split a pre-spaced two-column header ("San pham   Thanh tien") into its
     * left and right halves on the FIRST run of two or more spaces.
     *
     * The authored string is stored pre-spaced because that is how a human
     * types a column header into a form field — but a header laid out for 32
     * columns would collide or drift on 48, so the renderer re-justifies the
     * two halves to the real paper width. Re-justifying is presentation, not
     * computation: the words still come entirely from the definition.
     *
     * @return array{left: string, right: string}
     */
    public static function columnHeaderText(string $s): array
    {
        $chars = self::chars($s);
        $n = count($chars);

        for ($i = 0; $i + 1 < $n; $i++) {
            if ($chars[$i] === ' ' && $chars[$i + 1] === ' ') {
                $left = implode('', array_slice($chars, 0, $i));
                $j = $i;
                while ($j < $n && $chars[$j] === ' ') {
                    $j++;
                }

                return ['left' => $left, 'right' => implode('', array_slice($chars, $j))];
            }
        }

        return ['left' => $s, 'right' => ''];
    }

    /** Thousands-separated integer — no currency symbol, no decimals. */
    public static function formatPrice(int $amount): string
    {
        $s = (string) $amount;
        if (strlen($s) <= 3) {
            return $s;
        }

        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($i > 0 && ($len - $i) % 3 === 0) {
                $out .= ',';
            }
            $out .= $s[$i];
        }

        return $out;
    }

    /**
     * Wrap authored text to the paper by DISPLAY width.
     *
     * Ported from `wrapText` (print_shift_open_report.go): embedded newlines
     * are honoured as hard breaks, words are never split while they fit, and a
     * single token wider than the whole line is split character by character —
     * a spaceless Japanese sentence must still print.
     *
     * @return list<string>
     */
    public static function wrapText(string $s, int $width): array
    {
        if ($width < 1) {
            $width = 1;
        }

        $out = [];
        // Go splits on "\n" only — a lone \r is NOT a line break here.
        foreach (explode("\n", $s) as $paragraph) {
            foreach (self::wrapParagraph($paragraph, $width) as $line) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function wrapParagraph(string $para, int $width): array
    {
        $fields = preg_split('/\s+/u', trim($para), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($fields === []) {
            // An empty paragraph is still a line — a blank line the author
            // typed is part of the message.
            return [''];
        }

        $lines = [];
        $cur = '';
        $curW = 0;
        $flush = function () use (&$lines, &$cur, &$curW): void {
            if ($cur !== '') {
                $lines[] = $cur;
                $cur = '';
                $curW = 0;
            }
        };

        foreach ($fields as $word) {
            foreach (self::hardSplit($word, $width) as $piece) {
                $pw = self::displayWidth($piece);
                if ($curW === 0) {
                    $cur .= $piece;
                    $curW = $pw;
                } elseif ($curW + 1 + $pw <= $width) {
                    $cur .= ' '.$piece;
                    $curW += 1 + $pw;
                } else {
                    $flush();
                    $cur .= $piece;
                    $curW = $pw;
                }
            }
        }
        $flush();

        return $lines;
    }

    /**
     * Chop one token into chunks of at most `$width` columns, never splitting a
     * character. A token that already fits is returned whole.
     *
     * @return list<string>
     */
    private static function hardSplit(string $word, int $width): array
    {
        if (self::displayWidth($word) <= $width) {
            return [$word];
        }

        $chunks = [];
        $cur = '';
        $curW = 0;
        foreach (self::chars($word) as $ch) {
            $cw = self::charWidth($ch);
            if ($curW + $cw > $width && $curW > 0) {
                $chunks[] = $cur;
                $cur = '';
                $curW = 0;
            }
            $cur .= $ch;
            $curW += $cw;
        }
        if ($cur !== '') {
            $chunks[] = $cur;
        }

        return $chunks;
    }

    /**
     * Lay a menu-item name across printer lines: line 0 is `$firstW` columns
     * (the price sits to its right), continuation lines are `$contW`.
     *
     * Ported from `wrapNameLines`, including its widow control — a final line
     * that would carry a lone one-column character pulls the previous line's
     * last character down, so a name never ends with an orphan.
     *
     * @return list<string>
     */
    public static function wrapNameLines(string $name, int $firstW, int $contW): array
    {
        $firstW = max($firstW, 1);
        $contW = max($contW, 1);

        $lines = [];
        $cur = '';
        $curW = 0;

        // NOTE: `use (&$lines)`, not an arrow function — an arrow fn captures
        // by VALUE, so the limit would have stayed on `$firstW` forever and
        // every continuation line would have been laid out to the wrong width.
        $limit = function () use (&$lines, $firstW, $contW): int {
            return $lines === [] ? $firstW : $contW;
        };

        foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $ww = self::displayWidth($word);
            if ($curW === 0) {
                if ($ww <= $limit()) {
                    $cur = $word;
                    $curW = $ww;
                } else {
                    foreach (self::chars($word) as $ch) {
                        $cw = self::charWidth($ch);
                        if ($curW + $cw > $limit()) {
                            $lines[] = $cur;
                            $cur = '';
                            $curW = 0;
                        }
                        $cur .= $ch;
                        $curW += $cw;
                    }
                }

                continue;
            }
            if ($curW + 1 + $ww <= $limit()) {
                $cur .= ' '.$word;
                $curW += 1 + $ww;

                continue;
            }
            $lines[] = $cur;
            $cur = '';
            $curW = 0;
            if ($ww <= $limit()) {
                $cur = $word;
                $curW = $ww;
            } else {
                foreach (self::chars($word) as $ch) {
                    $cw = self::charWidth($ch);
                    if ($curW + $cw > $limit()) {
                        $lines[] = $cur;
                        $cur = '';
                        $curW = 0;
                    }
                    $cur .= $ch;
                    $curW += $cw;
                }
            }
        }
        $lines[] = $cur;

        if ($lines === []) {
            $lines = [''];
        }

        $n = count($lines);
        if ($n >= 2 && self::displayWidth($lines[$n - 1]) < 2) {
            $prev = self::chars($lines[$n - 2]);
            if (count($prev) > 1) {
                $moved = array_pop($prev);
                $lines[$n - 2] = implode('', $prev);
                $lines[$n - 1] = $moved.$lines[$n - 1];
            }
        }

        return $lines;
    }
}
