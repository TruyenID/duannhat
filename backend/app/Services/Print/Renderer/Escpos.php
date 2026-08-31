<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use App\Services\Printing\CloudPrntJobRenderer;

/**
 * plan-053 M5 (#1171) — the ESC/POS encoder, ported byte-for-byte from
 * `workstation/internal/printer/escpos/encoder.go`.
 *
 * Every constant here is a wire byte, so "equivalent" is not good enough: the
 * Go↔PHP parity gate hashes the whole stream, and a single different escape
 * sequence is a different receipt. Notes worth keeping:
 *
 *  - The target hardware is Star mC-Print3 in **StarPRNT** emulation, not
 *    Epson. Alignment is `ESC GS a` (1B 1D 61) and the cut is `ESC d n`
 *    (1B 64 n) — the Epson `ESC a` and `GS V` are silently ignored by this
 *    printer, which is how #438 shipped a receipt that never ejected.
 *  - `ESC @` is written at construction; `FS &` (kanji mode) deliberately is
 *    NOT — the mC-Print3 has no such mode and gets stuck waiting for
 *    multi-byte input, swallowing everything after it including the cut.
 *  - The left margin is applied per LINE, not per string, and only while the
 *    justification is left: the printer already places centred and
 *    right-aligned lines against the full printable width.
 *
 * Text is emitted in Shift_JIS. PHP's `SJIS` codec is byte-identical to Go's
 * `japanese.ShiftJIS` across the repertoire these slips use — verified
 * character by character — provided the substitution character is 0x1A
 * (Go's `encoding.ASCIISub`, not PHP's default `?`).
 */
final class Escpos
{
    public const INIT = "\x1B\x40";

    /** ESC d 3 — feed 3 lines + full cut (StarPRNT; callers need no extra feed). */
    public const CUT = "\x1B\x64\x33";

    public const PARTIAL_CUT = "\x1B\x64\x32";

    /**
     * ESC/POS `GS V 0` — full cut.
     *
     * The mC-Print3 in StarPRNT emulation IGNORES `GS V` (#438), which is
     * exactly why the command is chosen from the machine's capability profile
     * ({@see self::finish()}) rather than hard-coded: an `escpos_generic` box
     * understands `GS V` and not `ESC d`, a Star understands `ESC d` and not
     * `GS V`. No single byte sequence is right for both, so there is no correct
     * hard-coded cut.
     */
    public const GS_V_FULL_CUT = "\x1D\x56\x00";

    /** ESC/POS `GS V 1` — PARTIAL cut: leaves a tab of paper holding the slip. */
    public const GS_V_PARTIAL_CUT = "\x1D\x56\x01";

    public const OPEN_DRAWER = "\x1B\x70\x00\x19\xFA";

    public const LINE_FEED = "\x0A";

    public const ALIGN_LEFT = "\x1B\x1D\x61\x00";

    public const ALIGN_CENTER = "\x1B\x1D\x61\x01";

    public const ALIGN_RIGHT = "\x1B\x1D\x61\x02";

    public const BOLD_ON = "\x1B\x45\x01";

    public const BOLD_OFF = "\x1B\x45\x00";

    /** Character expansion is Star's `ESC i n1 n2`, NOT Epson's `ESC ! n`. */
    public const NORMAL_SIZE = "\x1B\x69\x00\x00";

    public const DOUBLE_HEIGHT = "\x1B\x69\x01\x00";

    public const DOUBLE_WIDTH = "\x1B\x69\x00\x01";

    public const DOUBLE_SIZE = "\x1B\x69\x01\x01";

    private string $buffer = '';

    private int $leftMargin = 0;

    private bool $atLineStart = true;

    private bool $leftAligned = true;

    /** Lệnh giãn ký tự đang chọn. */
    private string $size = self::NORMAL_SIZE;

    public function __construct()
    {
        $this->buffer = self::INIT;
    }

    /**
     * Indent every subsequent LEFT-aligned line by n blank columns, so a layout
     * authored for 42 columns prints centred on 48-column paper.
     */
    public function setLeftMargin(int $n): self
    {
        $this->leftMargin = max($n, 0);

        return $this;
    }

    public function text(string $s): self
    {
        if ($this->atLineStart && $s !== '') {
            if ($this->leftMargin > 0 && $this->leftAligned) {
                // Lề là BỐ CỤC, không phải nội dung: nó phải đo đúng bấy nhiêu
                // cột bất kể dòng bắt đầu ở cỡ chữ nào. Phát dưới ×2-chiều-rộng
                // thì mỗi khoảng trắng tốn hai cột, đẩy cả dòng sang phải đúng
                // bằng chiều rộng của chính cái lề.
                //
                // Chỉ chiều RỘNG mới ảnh hưởng. DOUBLE_HEIGHT giữ ô ký tự rộng
                // một cột nên không cần sửa và không được sửa: byte của mọi
                // caller hiện có phụ thuộc điều đó.
                if ($this->widthDoubled()) {
                    $this->buffer .= self::NORMAL_SIZE;
                    $this->buffer .= str_repeat(' ', $this->leftMargin);
                    $this->buffer .= $this->size;
                } else {
                    $this->buffer .= str_repeat(' ', $this->leftMargin);
                }
            }
            $this->atLineStart = false;
        }
        $this->buffer .= self::encodeShiftJis($s);

        return $this;
    }

    public function line(string $s): self
    {
        return $this->text($s)->feed(1);
    }

    public function feed(int $lines): self
    {
        for ($i = 0; $i < $lines; $i++) {
            $this->buffer .= self::LINE_FEED;
        }
        $this->atLineStart = true;

        return $this;
    }

    public function bold(bool $on): self
    {
        $this->buffer .= $on ? self::BOLD_ON : self::BOLD_OFF;

        return $this;
    }

    public function align(string $align): self
    {
        $this->buffer .= $align;
        $this->leftAligned = $align === self::ALIGN_LEFT;

        return $this;
    }

    public function size(string $size): self
    {
        $this->buffer .= $size;
        $this->size = $size;

        return $this;
    }

    /** Cỡ đang chọn có làm mỗi glyph chiếm hai cột không. */
    private function widthDoubled(): bool
    {
        return $this->size === self::DOUBLE_WIDTH || $this->size === self::DOUBLE_SIZE;
    }

    public function separator(int $width): self
    {
        $this->buffer .= str_repeat('-', max($width, 0)).self::LINE_FEED;

        return $this;
    }

    public function doubleSeparator(int $width): self
    {
        $this->buffer .= str_repeat('=', max($width, 0)).self::LINE_FEED;

        return $this;
    }

    /**
     * The StarPRNT full cut, `ESC d 3`.
     *
     * This is the NO-PROFILE path (#1950). It is not a default anyone chose for
     * a machine — it is the byte stream this renderer has always produced, kept
     * so a shop that has never configured a printer profile prints exactly what
     * it printed yesterday, and so the byte goldens and the Go↔PHP parity gate
     * keep meaning what they meant. Everything that KNOWS the machine goes
     * through {@see self::finish()} instead.
     */
    public function fullCut(): self
    {
        $this->buffer .= self::CUT;

        return $this;
    }

    /**
     * Apply the end-of-job behaviour this MACHINE declares (#1950).
     *
     * The PHP mirror of `escpos.Encoder.Finish`. Before #1950 the emitters ended
     * in a bare `fullCut()` that wrote `ESC d 3` whatever the printer was, so an
     * `epson_tm_i` — which declares `gs_v_partial` precisely so the slip stays
     * hanging in the mechanism instead of dropping on the floor — was sent a
     * full cut, and a tear-bar machine was sent a cut command it has no blade
     * for. The command now comes from the printer's capability profile, filled
     * in by the driver ({@see CloudPrntJobRenderer}) exactly as the workstation
     * fills it in `service.PrintRenderProfileFor`.
     *
     * P-36 [why `none` is an answer and not an absence]: a tear-bar machine
     * gets NO cut command. Some cheap firmware prints an unrecognised escape
     * sequence as literal garbage onto the next customer's slip, so the honest
     * action is to feed the paper clear of the head and stop.
     */
    public function finish(Finishing $f): self
    {
        if ($f->cutMode === Finishing::CUT_NONE) {
            // Still feed: without it the last lines sit inside the mechanism and
            // the operator tears through the total.
            return $this->feed(max($f->feedBeforeCut, 2));
        }

        if ($f->autoCutPerJob) {
            // The machine cuts on its own. Sending a cut as well produces a
            // second, blank slip every single time.
            return $this;
        }

        return match ($f->cutMode) {
            // ESC d n already feeds n lines before cutting, so an extra feed
            // here would double it.
            Finishing::CUT_ESC_D => $this->raw(self::CUT),
            Finishing::CUT_GS_V_PARTIAL => $this->feed($f->feedBeforeCut)->raw(self::GS_V_PARTIAL_CUT),
            default => $this->feed($f->feedBeforeCut)->raw(self::GS_V_FULL_CUT),
        };
    }

    /**
     * The epilogue on its own, with the constructor's `ESC @` stripped.
     *
     * Only the cross-language parity fixture needs this shape (`finishing_hex`
     * in `print_primitives_golden.json`): Go records the finishing bytes with
     * `Init` removed the same way, so the two sides are compared on the epilogue
     * alone rather than on a whole slip. Production always goes through
     * {@see PrintRenderContext::finish()}.
     */
    public static function finishBytes(Finishing $f): string
    {
        return substr((new self)->finish($f)->bytes(), strlen(self::INIT));
    }

    /**
     * StarPRNT QR (`ESC GS y`). The Epson `GS ( k` set is not supported by the
     * target printer, so this is the only sequence that produces a code.
     */
    public function qrCode(string $data, int $size): self
    {
        $size = max(1, min(8, $size));
        $len = strlen($data);

        $this->buffer .= "\x1B\x1D\x79\x53\x30\x02";          // model 2
        $this->buffer .= "\x1B\x1D\x79\x53\x31\x01";          // EC level M
        $this->buffer .= "\x1B\x1D\x79\x53\x32".chr($size);   // cell size
        $this->buffer .= "\x1B\x1D\x79\x44\x31\x00".chr($len & 0xFF).chr(($len >> 8) & 0xFF);
        $this->buffer .= $data;
        $this->buffer .= "\x1B\x1D\x79\x50";                  // print

        return $this;
    }

    /**
     * `GS v 0` raster bit-image — the command that puts a LOGO on paper (#1957).
     *
     * Neither repo had one until now: this encoder could align, embolden, switch
     * to kanji mode and cut, but had no way to say "here are some dots". That
     * absence is why the `logo` block is togglable in the catalog while nothing
     * draws it (#1949) — the catalog offered a capability the byte layer could
     * not express.
     *
     * ## Bytes only
     *
     * Takes an ALREADY-DECODED 1-bit bitmap, packed MSB-first with each row
     * padded to a whole byte — the layout the command itself uses, so nothing is
     * repacked here and there is nothing for the two languages to disagree
     * about. Decoding PNG/JPEG belongs to the upload path: two image DECODERS
     * agreeing pixel-for-pixel is a far larger promise than two ENCODERS
     * agreeing on an eight-byte header, and only the second can be pinned by a
     * golden fixture.
     *
     * ## Why `GS v 0` rather than `ESC *`
     *
     * `ESC *` is column mode — the caller slices the image into bands and
     * interleaves line feeds, so the same picture becomes different bytes
     * depending on how it was sliced. `GS v 0` carries the whole raster in one
     * command, which is the form two independent implementations can be expected
     * to produce identically.
     *
     * ## Silent no-op on bad input, deliberately
     *
     * TR-05: a workstation that has never been online has no logo bytes, and a
     * slip that refuses to print because a decoration is missing is a worse
     * failure than a slip without the decoration. Mirrors Go's `Raster`, which
     * returns `false` and writes nothing.
     *
     * @param  string  $data  packed 1bpp rows, MSB first
     */
    public function raster(int $widthDots, string $data): self
    {
        if ($widthDots <= 0 || $data === '') {
            return $this;
        }

        $bytesPerRow = intdiv($widthDots + 7, 8);
        if ($bytesPerRow === 0 || strlen($data) % $bytesPerRow !== 0) {
            // A partial final row means the caller packed the image differently
            // from what it declared. Printing it would shear the picture, and
            // the operator would report "the logo looks corrupted" with no way
            // to trace it back here.
            return $this;
        }

        $heightDots = intdiv(strlen($data), $bytesPerRow);
        if ($bytesPerRow > 0xFFFF || $heightDots > 0xFFFF) {
            // `xL xH` / `yL yH` are 16-bit; the wire format cannot say more.
            return $this;
        }

        // m = 0 — normal density, no scaling. The scaled modes double width or
        // height ON THE PRINTER, which would make the rendered size depend on
        // the machine rather than on the definition.
        $this->buffer .= "\x1D\x76\x30\x00"
            .chr($bytesPerRow & 0xFF).chr($bytesPerRow >> 8)
            .chr($heightDots & 0xFF).chr($heightDots >> 8)
            .$data;

        return $this;
    }

    public function raw(string $data): self
    {
        $this->buffer .= $data;

        return $this;
    }

    public function bytes(): string
    {
        return $this->buffer;
    }

    /** Current stream length — the segmenter uses it as a block boundary. */
    public function length(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Fold Latin/Vietnamese diacritics to plain ASCII.
     *
     * The printer codepage has no Vietnamese glyphs, so "phở đặc biệt" must
     * become "pho dac biet" to render at all. CJK is passed through UNTOUCHED —
     * NFD-decomposing Japanese splits voiced kana (が → か + ゛) and would
     * corrupt the store name. đ/Đ have no canonical decomposition, so they are
     * mapped by hand; every other accented vowel decomposes into a base letter
     * plus combining marks that are then dropped.
     */
    public static function stripAccents(string $s): string
    {
        // Fast path: pure ASCII (order codes, prices) needs no work.
        if (! preg_match('/[\x80-\xFF]/', $s)) {
            return $s;
        }

        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp === false) {
                continue;
            }

            // Standalone combining marks (already-decomposed input) print nothing.
            if (preg_match('/^[\p{Mn}\p{Me}]$/u', $ch) === 1) {
                continue;
            }
            if ($ch === 'đ') {
                $out .= 'd';

                continue;
            }
            if ($ch === 'Đ') {
                $out .= 'D';

                continue;
            }
            if ($cp < 0x80) {
                $out .= $ch;

                continue;
            }
            if (preg_match('/^\p{Latin}$/u', $ch) === 1) {
                $decomposed = \Normalizer::normalize($ch, \Normalizer::FORM_D);
                if (! is_string($decomposed)) {
                    $decomposed = $ch;
                }
                foreach (preg_split('//u', $decomposed, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $dch) {
                    if (preg_match('/^[\p{Mn}\p{Me}]$/u', $dch) === 1) {
                        continue;
                    }
                    $out .= $dch;
                }

                continue;
            }
            // Non-Latin (CJK, currency symbols, punctuation) is left as-is.
            $out .= $ch;
        }

        return $out;
    }

    /**
     * UTF-8 → Shift_JIS, matching Go's
     * `encoding.ReplaceUnsupported(japanese.ShiftJIS.NewEncoder())`.
     *
     * The two pre-substitutions are not cosmetic:
     *  - U+00A5 (¥) is absent from Shift_JIS, but 0x5C IS the yen sign in the
     *    JIS codepage every Star/Epson thermal printer uses. Encoding it
     *    normally would emit the 0x1A substitute and print nothing.
     *  - U+20AB (₫) has no glyph at all, so a VND slip prints the ASCII "d"
     *    (đồng) rather than a substitution mark.
     *
     * Anything still outside the repertoire becomes 0x1A — Go's
     * `encoding.ASCIISub`. PHP's default substitute is `?`, which would have
     * been a silent one-byte divergence on every em dash.
     */
    public static function encodeShiftJis(string $s): string
    {
        if ($s === '') {
            return '';
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0x1A);

        try {
            // Fast path: pure ASCII is already Shift_JIS, byte for byte. It
            // needs no folding, no override lookup and no ¥/₫ mapping (both
            // are multibyte, so neither can be here), and it is most of what a
            // slip prints — order codes, prices, Vietnamese menu names once
            // folded. Keeping it off the per-character loop matters on a busy
            // till.
            if (! preg_match('/[\x80-\xFF]/', $s)) {
                return $s;
            }

            $out = '';
            foreach (Layout::chars($s) as $ch) {
                $cp = mb_ord($ch, 'UTF-8');

                /*
                 * The override table is consulted on the ORIGINAL character,
                 * BEFORE accent folding. That ordering is load-bearing: PHP's
                 * PCRE ships newer Unicode data than Go's `unicode` tables, so
                 * the two disagree about the general category of recently
                 * added combining marks (U+0897 ARABIC PEPET, added in Unicode
                 * 16). PHP would fold it away to nothing while Go keeps it and
                 * substitutes — a one-byte divergence that no amount of
                 * post-folding correction can reach, because by then the
                 * character is gone.
                 */
                if ($cp !== false) {
                    $override = ShiftJisRepertoire::lookup($cp);
                    if ($override !== null) {
                        $out .= $override;

                        continue;
                    }
                }

                $folded = self::stripAccents($ch);
                $folded = str_replace(['¥', '₫'], ["\x5C", 'd'], $folded);
                if ($folded === '') {
                    continue;
                }
                $out .= mb_convert_encoding($folded, 'SJIS', 'UTF-8');
            }

            return $out;
        } finally {
            mb_substitute_character($previous);
        }
    }
}
