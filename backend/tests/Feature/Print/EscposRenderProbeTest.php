<?php

declare(strict_types=1);

/**
 * plan-053 M5 (#1171) T5.1 — the render trial, now backed by the real ESC/POS
 * primitives.
 *
 * The interesting half is the check the old geometry-only probe could not make
 * at all: whether the brand's text can be PRINTED. A character with no glyph in
 * the printer's Shift_JIS codepage does not fail, does not warn, and does not
 * look wrong anywhere in the browser — it just comes out of the till as a blank
 * or a black block, on every slip, until somebody phones support. Catching it
 * at publish is the difference between a 422 in front of the author and a
 * fortnight of receipts nobody can read.
 */

use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\EscposRenderProbe;
use App\Services\Print\RenderProbe;
use App\Services\Print\SystemTemplateDefaults;

beforeEach(function () {
    $this->probe = app(EscposRenderProbe::class);
    $this->defaults = app(SystemTemplateDefaults::class);
});

/** A receipt definition whose footer carries the given text. */
function definitionWithFooter(string $text, string $locale = 'ja'): array
{
    $definition = app(SystemTemplateDefaults::class)->forKind(PrintTemplateKind::Receipt);

    foreach ($definition['blocks'] as $i => $block) {
        if (($block['id'] ?? null) === 'footer_text') {
            $definition['blocks'][$i] = array_replace($block, [
                'enabled' => true,
                'fallback' => true,
                'i18n' => [$locale => $text],
            ]);
        }
    }

    return $definition;
}

/** @return list<string> */
function probeCodes(array $definition): array
{
    return array_map(
        fn (array $v): string => $v['code'],
        app(EscposRenderProbe::class)->probe($definition, PrintTemplateKind::Receipt),
    );
}

it('E1: the container now resolves the ESC/POS probe, not the structural stand-in', function () {
    expect(app(RenderProbe::class))->toBeInstanceOf(EscposRenderProbe::class);
});

it('E2: every untouched system default passes the trial', function () {
    // The shipped baseline must be publishable — a gate that rejects the thing
    // it ships is a gate nobody can satisfy.
    foreach (PrintTemplateKind::cases() as $kind) {
        expect($this->probe->probe($this->defaults->forKind($kind), $kind))
            ->toBe([], "the system default for {$kind->value} fails its own render trial");
    }
});

it('E3: ordinary Japanese, English and Vietnamese all pass', function () {
    foreach ([
        'ja' => 'ありがとうございました またお越しくださいませ',
        'en' => 'Thank you for your visit',
        'vi' => 'Cam on quy khach',
    ] as $locale => $text) {
        expect(probeCodes(definitionWithFooter($text, $locale)))->toBe([], "locale {$locale}");
    }
});

it('E4: accented Vietnamese passes — it is FOLDED to ASCII, not rejected', function () {
    // "phở đặc biệt" has no Shift_JIS glyphs at all, but the encoder folds Latin
    // diacritics before encoding, so it prints as "pho dac biet". Rejecting it
    // would make the product unusable in its second market.
    expect(probeCodes(definitionWithFooter('Cảm ơn quý khách - phở đặc biệt Hà Nội', 'vi')))
        ->not->toContain('RENDER_TRIAL_UNPRINTABLE_CHARACTER');
});

it('E5: rejects a character with no glyph in the printer codepage', function () {
    // An em dash is the classic case: a brand pastes copy out of a word
    // processor and every receipt gets a black block where the dash was.
    $codes = probeCodes(definitionWithFooter('Thank you — come again'));

    expect($codes)->toContain('RENDER_TRIAL_UNPRINTABLE_CHARACTER');
});

it('E6: names the offending character and its code point so the author can find it', function () {
    $violations = $this->probe->probe(definitionWithFooter('Sale € only'), PrintTemplateKind::Receipt);
    $message = collect($violations)
        ->firstWhere('code', 'RENDER_TRIAL_UNPRINTABLE_CHARACTER')['message'] ?? '';

    expect($message)->toContain('€')
        ->and($message)->toContain('U+20AC')
        ->and($message)->toContain('Shift_JIS');
});

it('E7: reports the block and locale path, not just "somewhere"', function () {
    $violations = $this->probe->probe(definitionWithFooter('€', 'en'), PrintTemplateKind::Receipt);

    expect(collect($violations)->pluck('path'))->toContain('blocks.footer_text.i18n.en');
});

it('E8: lists several offenders at once rather than one per publish attempt', function () {
    $violations = $this->probe->probe(definitionWithFooter('€ £ ₩ ฿ ₱'), PrintTemplateKind::Receipt);
    $message = collect($violations)
        ->firstWhere('code', 'RENDER_TRIAL_UNPRINTABLE_CHARACTER')['message'] ?? '';

    // Fixing these one 422 at a time would be five round trips.
    expect(substr_count($message, 'U+'))->toBeGreaterThan(1);
});

it('E9: ¥ and ₫ pass — they are mapped to printable substitutes, not rejected', function () {
    // ¥ becomes 0x5C (the yen sign in the printer's JIS codepage) and ₫ becomes
    // an ASCII "d". Flagging the shop's own currency symbol would be absurd.
    expect(probeCodes(definitionWithFooter('¥1,000 / ₫50.000')))
        ->not->toContain('RENDER_TRIAL_UNPRINTABLE_CHARACTER');
});

it('E10: the NEC extension characters the printer DOES have are accepted', function () {
    // ①②③ and ー are everywhere in Japanese shop copy. PHP's plain SJIS codec
    // substitutes them and the workstation does not, so without the
    // repertoire override table this gate would have rejected perfectly
    // printable text.
    expect(probeCodes(definitionWithFooter('営業時間 ①②③ コーヒー')))
        ->not->toContain('RENDER_TRIAL_UNPRINTABLE_CHARACTER');
});

it('E10b: catches the wave dash 〜 (U+301C) — the trap in every Japanese opening-hours line', function () {
    /*
     * This one is worth stating plainly, because it is the single most likely
     * way a real brand breaks its own receipt.
     *
     * Japanese opening hours are written "10:00〜22:00". Two characters look
     * identical in a browser and are NOT interchangeable on the printer:
     *
     *   U+301C 〜 WAVE DASH        — no glyph, prints as a blob
     *   U+FF5E ～ FULLWIDTH TILDE  — prints correctly
     *
     * macOS and most Japanese IMEs emit U+301C. So the natural thing to type
     * is the broken one, and nothing anywhere on screen would ever say so.
     */
    expect(probeCodes(definitionWithFooter('営業 10:00〜22:00')))
        ->toContain('RENDER_TRIAL_UNPRINTABLE_CHARACTER');

    expect(probeCodes(definitionWithFooter('営業 10:00～22:00')))
        ->not->toContain('RENDER_TRIAL_UNPRINTABLE_CHARACTER');
});

it('E11: an unbreakable token wider than 58mm paper is still rejected, naming the width', function () {
    $violations = $this->probe->probe(
        definitionWithFooter(str_repeat('A', 40)),
        PrintTemplateKind::Receipt,
    );

    $message = collect($violations)->firstWhere('code', 'RENDER_TRIAL_FAILED')['message'] ?? '';

    expect($message)->toContain('58mm')
        // 40 columns fits 80mm paper — reporting both would send the author
        // hunting for a problem that does not exist there.
        ->and($message)->not->toContain('80mm paper');
});

it('E12: long but WRAPPABLE text passes — the renderer wraps (TR-20)', function () {
    expect(probeCodes(definitionWithFooter(trim(str_repeat('word ', 40)))))->toBe([]);
});

it('E13: measures DISPLAY width — 20 fullwidth characters are 40 columns', function () {
    expect(probeCodes(definitionWithFooter(str_repeat('あ', 20))))->toContain('RENDER_TRIAL_FAILED');
});

it('E14: measures an EMOJI as two columns, which the structural probe did not', function () {
    /*
     * This is the concrete reason the probe was replaced. The old width table
     * omitted the emoji range, so it measured these as one column each and
     * passed a 34-column token as 17 — a layout that overflows 58mm paper on
     * every slip, waved through by the gate that exists to catch exactly that.
     */
    expect(probeCodes(definitionWithFooter(str_repeat('🍜', 17))))->toContain('RENDER_TRIAL_FAILED');
});

it('E15: a disabled text block is not probed — it prints nothing', function () {
    $definition = definitionWithFooter('€ — unprintable');
    foreach ($definition['blocks'] as $i => $block) {
        if (($block['id'] ?? null) === 'footer_text') {
            $definition['blocks'][$i]['enabled'] = false;
        }
    }

    expect(probeCodes($definition))->toBe([]);
});

it('E16: i18n_narrow is probed too — the 58mm variant is a real slip', function () {
    $definition = $this->defaults->forKind(PrintTemplateKind::VatInvoice);
    foreach ($definition['blocks'] as $i => $block) {
        if (($block['id'] ?? null) === 'title') {
            $definition['blocks'][$i]['i18n_narrow'] = ['ja' => 'HOA DON €'];
        }
    }

    $codes = array_map(
        fn (array $v): string => $v['code'],
        $this->probe->probe($definition, PrintTemplateKind::VatInvoice),
    );

    expect($codes)->toContain('RENDER_TRIAL_UNPRINTABLE_CHARACTER');
});

it('E17: a malformed definition yields no violations rather than an exception', function () {
    // The probe runs inside the publish path; a crash here would be a 500 on a
    // save, which is a worse experience than the 422 it exists to produce.
    foreach ([
        [],
        ['blocks' => 'nonsense'],
        ['blocks' => [null, 42, 'text']],
        ['blocks' => [['id' => 'x']]],
        ['blocks' => [['id' => 'x', 'type' => 'text', 'i18n' => 'not-an-array']]],
        ['paper' => 'wrong', 'blocks' => []],
    ] as $definition) {
        expect($this->probe->probe($definition, PrintTemplateKind::Receipt))->toBeArray();
    }
});

it('E18: a zero or missing paper width is skipped, not divided by', function () {
    $definition = definitionWithFooter(str_repeat('A', 40));
    $definition['paper'] = ['columns_58mm' => 0, 'columns_80mm' => 0];

    expect(probeCodes($definition))->not->toContain('RENDER_TRIAL_FAILED');
});
