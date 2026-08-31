<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Services\Print\Enums\PrintTemplateKind;

/**
 * plan-053 M2 (#1171) — the renderer-free half of DESIGN §4 rule 6.
 *
 * It answers the one question a layout can answer without a renderer: does
 * every authored string physically FIT the paper, in every locale, at both
 * widths. Text that is merely long is fine — TR-20 says the renderer wraps at
 * the profile's column count. What can never be made to fit is a single
 * UNBREAKABLE token wider than the paper (a URL, a 40-character product code,
 * a run of fullwidth kana): no wrapping algorithm can save it, so it prints
 * truncated or bleeds into the next line forever.
 *
 * Width is measured in DISPLAY columns, mirroring the workstation's
 * `displayWidth` — CJK/fullwidth characters occupy two columns, so counting
 * runes under-measures a Japanese header by half and would pass a layout that
 * overflows on the actual printer.
 *
 * A violation names WHICH paper width failed (V10), because "it doesn't fit"
 * without the width is not actionable: 58mm and 80mm shops in the same brand
 * share one definition.
 */
class StructuralRenderProbe implements RenderProbe
{
    /** Text modes the plan requires publish to cover (DESIGN §4.6). */
    private const TEXT_MODES = ['native', 'raster'];

    public function __construct(private readonly BlockCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array{code: string, path: string, message: string}>
     */
    public function probe(array $definition, PrintTemplateKind $kind): array
    {
        $violations = [];

        $paper = is_array($definition['paper'] ?? null)
            ? $definition['paper']
            : $this->catalog->paper();

        /** @var array<string, int> $widths */
        $widths = [
            '58mm' => (int) ($paper['columns_58mm'] ?? 32),
            '80mm' => (int) ($paper['columns_80mm'] ?? 48),
        ];

        /** @var list<string> $locales */
        $locales = array_values((array) config('print_templates.locales', ['ja', 'en', 'vi']));

        foreach ($definition['blocks'] ?? [] as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }
            if (($block['enabled'] ?? true) === false) {
                continue;
            }
            $blockId = (string) ($block['id'] ?? '?');
            $i18n = is_array($block['i18n'] ?? null) ? $block['i18n'] : [];

            foreach ($locales as $locale) {
                $text = $i18n[$locale] ?? null;
                if (! is_string($text) || $text === '') {
                    continue;
                }

                foreach ($widths as $paperLabel => $columns) {
                    if ($columns <= 0) {
                        continue;
                    }
                    $offender = $this->unwrappableToken($text, $columns);
                    if ($offender === null) {
                        continue;
                    }

                    // The same geometry failure exists in both text modes —
                    // raster draws the identical glyph run — so report it once
                    // per (paper, locale) naming the modes it was proved for.
                    $modes = implode('/', self::TEXT_MODES);
                    $violations[] = [
                        'code' => 'RENDER_TRIAL_FAILED',
                        'path' => "blocks.{$blockId}.i18n.{$locale}",
                        'message' => sprintf(
                            'Render trial failed on %s paper (%d columns, locale %s, text_mode %s): "%s" is %d columns wide and cannot be wrapped.',
                            $paperLabel,
                            $columns,
                            $locale,
                            $modes,
                            $offender,
                            $this->displayWidth($offender),
                        ),
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * The first whitespace-delimited token too wide for the paper, or null
     * when the text can be wrapped into `$columns`.
     */
    private function unwrappableToken(string $text, int $columns): ?string
    {
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if ($this->displayWidth($token) > $columns) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Display columns, not runes: fullwidth (CJK, kana, fullwidth forms) takes
     * two columns on a thermal printer — the same rule as the workstation's
     * `displayWidth`.
     */
    private function displayWidth(string $text): int
    {
        $width = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $code = mb_ord($char, 'UTF-8');
            $width += $this->isFullwidth((int) $code) ? 2 : 1;
        }

        return $width;
    }

    private function isFullwidth(int $code): bool
    {
        return ($code >= 0x1100 && $code <= 0x115F)      // Hangul Jamo
            || ($code >= 0x2E80 && $code <= 0x303E)      // CJK radicals, kangxi, punctuation
            || ($code >= 0x3041 && $code <= 0x33FF)      // kana, CJK compatibility
            || ($code >= 0x3400 && $code <= 0x4DBF)      // CJK ext A
            || ($code >= 0x4E00 && $code <= 0x9FFF)      // CJK unified
            || ($code >= 0xA000 && $code <= 0xA4CF)      // Yi
            || ($code >= 0xAC00 && $code <= 0xD7A3)      // Hangul syllables
            || ($code >= 0xF900 && $code <= 0xFAFF)      // CJK compatibility ideographs
            || ($code >= 0xFE30 && $code <= 0xFE6F)      // CJK compatibility forms
            || ($code >= 0xFF00 && $code <= 0xFF60)      // fullwidth forms
            || ($code >= 0xFFE0 && $code <= 0xFFE6);
    }
}
