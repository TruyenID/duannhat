<?php

declare(strict_types=1);

/**
 * plan-053 M5 (#1171) TR-34 — Go↔PHP parity for the render PRIMITIVES.
 *
 * The slip-level gate compares whole ESC/POS streams. That is the assertion
 * that matters, and the worst possible place to debug a port: a one-column
 * error in `displayWidth` shows up as "receipt|ja|32 hash differs" and nothing
 * else, while actually being wrong on every line of every kind.
 *
 * So the primitives every emitter stands on are gated separately against the
 * SAME recorded fixture the Go test writes — no duplicated expectations, one
 * source of truth. When the port drifts, the failure names the function and
 * the input.
 *
 * Fixture: `workstation/internal/service/testdata/print_primitives_golden.json`
 * Regenerate: `go test ./internal/service/ -run Primitives_Golden -args -update-print-primitives`
 */

use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\Finishing;
use App\Services\Print\Renderer\Layout;

/** @return array<string, mixed> */
function primitivesGolden(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $path = base_path('../workstation/internal/service/testdata/print_primitives_golden.json');
    expect(file_exists($path))->toBeTrue("missing primitives fixture: {$path}");

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $cache = $decoded;
}

/**
 * Assert every recorded case, reporting ALL divergences at once.
 *
 * Reporting one failure at a time would mean one edit per run through a
 * fixture with hundreds of cases; seeing the whole shape of the disagreement
 * is what makes it obvious whether the bug is in the width table, the wrap
 * loop or the encoder.
 */
function expectPrimitiveGroup(string $group, callable $compute): void
{
    $cases = primitivesGolden()[$group] ?? null;
    expect($cases)->toBeArray("fixture group `{$group}` is missing");

    $mismatches = [];
    foreach ($cases as $key => $want) {
        // PHP silently coerces numeric array keys to int; the fixture's keys
        // are strings on the Go side, so cast back before dispatching.
        $got = $compute((string) $key);
        if ($got !== $want) {
            $mismatches[] = sprintf(
                "  %s\n     want: %s\n      got: %s",
                json_encode($key, JSON_UNESCAPED_UNICODE),
                json_encode($want, JSON_UNESCAPED_UNICODE),
                json_encode($got, JSON_UNESCAPED_UNICODE),
            );
        }
    }

    expect($mismatches)->toBe([], sprintf(
        "%d/%d `%s` cases diverge from Go:\n%s",
        count($mismatches),
        count($cases),
        $group,
        implode("\n", array_slice($mismatches, 0, 12)),
    ));
}

/** Split a "text|int" or "text|int|int" fixture key from the RIGHT. */
function splitPrimitiveKey(string $key, int $numbers): array
{
    $parts = explode('|', $key);
    $tail = array_splice($parts, count($parts) - $numbers);

    return [implode('|', $parts), ...array_map('intval', $tail)];
}

/*
 * Cross-REPO gate: this suite reads golden fixtures out of the
 * `workstation/` IN-TREE (#2306, trước là submodule anh em). Trên CI cây là
 * separate PRIVATE repos that GITHUB_TOKEN cannot clone, so the files are
 * simply absent — skip the whole file loudly there instead of reporting a
 * parity failure that is really a missing checkout. Wherever the submodule
 * IS present (every dev machine, and CI once a cross-repo credential
 * exists) the parity contract is enforced exactly as before.
 */
beforeEach(function (): void {
    if (! is_dir(base_path('../workstation/internal/service'))) {
        test()->markTestSkipped('nguồn workstation vắng mặt trong cây (in-tree từ #2306) — cổng parity bị bỏ qua');
    }
});

it('M1: displayWidth matches Go for every sample', function () {
    expectPrimitiveGroup('display_width', fn (string $s): int => Layout::displayWidth($s));
});

it('M2: runeLength matches Go — the VAT invoice measures code points, not columns', function () {
    expectPrimitiveGroup('rune_len', fn (string $s): int => Layout::runeLength($s));
});

it('M3: formatPrice matches Go, including negatives and the 3-digit boundary', function () {
    expectPrimitiveGroup('format_price', fn (string $n): string => Layout::formatPrice((int) $n));
});

it('M4: dashedLine matches Go at every paper width', function () {
    expectPrimitiveGroup('dashed_line', fn (string $w): string => Layout::dashedLine((int) $w));
});

it('M5: padRight matches Go — padding is by DISPLAY width', function () {
    expectPrimitiveGroup('pad_right', function (string $key): string {
        [$s, $w] = splitPrimitiveKey($key, 1);

        return Layout::padRight($s, $w);
    });
});

it('M6: wrapText matches Go — fullwidth, unbreakable tokens, newlines, blanks', function () {
    expectPrimitiveGroup('wrap_text', function (string $key): array {
        [$s, $w] = splitPrimitiveKey($key, 1);

        return Layout::wrapText($s, $w);
    });
});

it('M7: wrapNameLines matches Go, widow control included', function () {
    expectPrimitiveGroup('wrap_name_lines', function (string $key): array {
        [$s, $fw, $cw] = splitPrimitiveKey($key, 2);

        return Layout::wrapNameLines($s, $fw, $cw);
    });
});

it('M8: columnHeaderText splits the two-column header exactly as Go does', function () {
    expectPrimitiveGroup('column_header_text', fn (string $s): array => array_values(Layout::columnHeaderText($s)));
});

it('M9: StripAccents folds Latin/Vietnamese and leaves CJK alone', function () {
    // The CJK half is the load-bearing one: NFD-decomposing Japanese would
    // split voiced kana (が → か + ゛) and corrupt the store name.
    expectPrimitiveGroup('strip_accents', fn (string $s): string => Escpos::stripAccents($s));
});

it('M11: the ENTIRE Shift_JIS repertoire is byte-identical to Go', function () {
    /*
     * The sample list above covers what a slip prints. This covers what a
     * BRAND can type into a footer, which is all of Unicode.
     *
     * PHP's `SJIS` codec and Go's disagree on 456 BMP code points and neither
     * is a superset of the other: PHP encodes ¢ £ ¥ ¬ ¯ ‖ ‾ − 〜 that Go
     * substitutes, Go encodes the 447 NEC/IBM extension characters (① ② ③,
     * Ⅰ–Ⅹ, ℡, №, ∑, extension kanji) that PHP substitutes. 〜 on its own is
     * disqualifying — Japanese opening hours are written "10:00〜22:00".
     *
     * `CP932` is not the answer either: it disagrees on 2262. Plain `SJIS`
     * plus the generated ShiftJisRepertoire override table is, and hashing
     * every code point is what proves the table is complete rather than
     * merely plausible.
     */
    $digest = primitivesGolden()['shift_jis_repertoire_sha256'] ?? null;
    expect($digest)->toBeString('fixture is missing the repertoire digest');

    $buffer = '';
    for ($cp = 0x20; $cp <= 0xFFFF; $cp++) {
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            continue; // lone surrogates are not characters
        }
        $buffer .= Escpos::encodeShiftJis(mb_chr($cp, 'UTF-8'));
        $buffer .= "\x00"; // separator, so two adjacent encodings cannot alias
    }

    expect(hash('sha256', $buffer))->toBe(
        $digest,
        'PHP and Go disagree somewhere in the Shift_JIS repertoire — regenerate ShiftJisRepertoire::OVERRIDES.',
    );
});

it('M12: the finishing epilogue is byte-identical to Go for every cut dialect', function () {
    /*
     * #1950 — the cut left the renderers and became the DRIVER's job on both
     * sides (`printer.PrintDocument` in Go, `CloudPrntJobRenderer` here). That
     * is the right boundary, but it took the cut OUT of the slip-level parity
     * gate, which compares renderer output: without this group each repo would
     * be free to pick its own bytes for the same declared profile, and the
     * fleet would only find out on paper.
     *
     * Two rows carry the bug that was fixed. `gs_v_partial` is what an
     * `epson_tm_i` declares so the slip stays hanging in the mechanism instead
     * of dropping on the floor — it had been receiving a full cut. `none` is a
     * tear-bar machine, which must still be FED (or the last line sits inside
     * the mechanism and the operator tears through the total) and must receive
     * NO cut command at all (P-36).
     */
    expectPrimitiveGroup('finishing_hex', function (string $key): string {
        [$mode, $feed, $auto] = explode('|', $key);

        return bin2hex(Escpos::finishBytes(new Finishing(
            cutMode: $mode,
            feedBeforeCut: (int) $feed,
            autoCutPerJob: $auto === 'true',
        )));
    });
});

it('M13: the raster bit-image command is byte-identical to Go (#1957)', function () {
    /*
     * #1957 piece A — the command that puts a LOGO on paper. Neither repo had
     * one: the template catalog has offered a togglable `logo` block all along
     * while nothing could draw it (#1949), because the byte layer could not
     * express dots.
     *
     * Pinned across repos because a logo is the first thing a shop notices and
     * the last thing a test covers. It either appears or it does not — and
     * "it appeared but sheared by one dot row" is invisible in review and
     * obvious on paper. The header carries both dimensions as little-endian
     * pairs, so an endianness slip in either language yields a picture that is
     * subtly the wrong shape rather than an error.
     *
     * The three refusal cases matter as much as the three drawing ones. TR-05
     * says a machine that has never been online must still print, so bad or
     * absent image bytes emit NOTHING and the slip goes out without the
     * decoration — never an exception. `ragged_rows` is the one worth naming:
     * a byte count that is not a whole number of rows would SHEAR the picture,
     * so it is refused rather than drawn wrong.
     */
    // The INPUTS are duplicated here rather than read from the fixture, because
    // the fixture records only what Go PRODUCED. Sharing the inputs too would
    // mean a typo in one language silently changes what both sides are asked to
    // encode, and the comparison would still pass.
    $cases = [
        '8x1_solid' => [8, "\xFF"],
        '8x2_stripes' => [8, "\xAA\x55"],
        '12x2_padded' => [12, "\xFF\xF0\x0F\xF0"],
        '1x8_column' => [1, str_repeat("\x80", 8)],
        'ragged_rows' => [12, "\xFF\xF0\x0F"],
        'zero_width' => [0, "\xFF"],
        'empty_data' => [8, ''],
    ];

    expectPrimitiveGroup('raster_hex', function (string $key) use ($cases): string {
        [$width, $data] = $cases[$key];

        $e = new Escpos;
        $before = $e->length();
        $e->raster($width, $data);
        $drew = $e->length() > $before;

        return ($drew ? 'true' : 'false').':'.bin2hex($e->bytes());
    });
});

it('M10: Shift_JIS output is byte-identical to Go, substitution included', function () {
    /*
     * PHP's default substitution character is `?`; Go's is 0x1A
     * (`encoding.ASCIISub`). Every em dash in a topping line would have been a
     * silent one-byte divergence — invisible in a text diff, fatal to a hash.
     */
    expectPrimitiveGroup('shift_jis_hex', fn (string $s): string => bin2hex(Escpos::encodeShiftJis($s)));
});
