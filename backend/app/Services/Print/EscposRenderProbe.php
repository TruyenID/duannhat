<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\Layout;

/**
 * plan-053 M5 (#1171) T5.1 — the render trial, backed by the real encoder.
 *
 * This replaces {@see StructuralRenderProbe}, which could only measure
 * geometry and did so with its OWN width table — one that quietly disagreed
 * with the workstation's on emoji (measured 1 column, prints 2), angle
 * brackets, and the top of the Hangul block. A publish gate whose ruler is a
 * different length from the printer's is worse than no gate: it passes layouts
 * that overflow and it is believed.
 *
 * Two things are checked now, both against the SAME primitives the ESC/POS
 * renderer uses ({@see Layout}, {@see Escpos}), so the answer here and the
 * bytes on the paper cannot drift apart:
 *
 *  1. GEOMETRY — every authored string is wrapped with the production
 *     `wrapText` at each paper width. Long text is fine (TR-20 says the
 *     renderer wraps); what can never be made to fit is a single unbreakable
 *     token wider than the paper, so that is what fails.
 *
 *  2. ENCODABILITY — the string is actually encoded to Shift_JIS and checked
 *     for substitution marks (0x1A). This is the check the structural probe
 *     could not perform at all, and it catches a whole class of complaint that
 *     used to reach the shop: a brand pastes a curly quote, an em dash, a "①",
 *     or a Windows-only glyph into its footer, everything looks correct in the
 *     browser, and the till prints a black blob. The character never had a
 *     representation in the printer's codepage — but nothing said so until the
 *     customer was holding the receipt.
 *
 * Both run across the full DESIGN §4.6 matrix: 2 paper widths × 3 locales.
 * Text mode is named in the message rather than iterated, because native and
 * raster draw the identical glyph run — the geometry and the codepage are the
 * same question in both, and reporting each failure twice would only make the
 * 422 harder to read.
 */
class EscposRenderProbe implements RenderProbe
{
    /** Text modes this trial's result is valid for (DESIGN §4.6). */
    private const TEXT_MODES = ['native', 'raster'];

    /** The byte Shift_JIS encoding emits for a character it cannot represent. */
    private const SUBSTITUTE = "\x1A";

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

        $widths = [
            '58mm' => (int) ($paper['columns_58mm'] ?? 32),
            '80mm' => (int) ($paper['columns_80mm'] ?? 48),
        ];

        /** @var list<string> $locales */
        $locales = array_values((array) config('print_templates.locales', ['ja', 'en', 'vi']));

        // The probe runs inside the publish path, and a definition can reach
        // it in any shape at all (a hand-rolled API call, a newer Cloud, a
        // half-migrated row). A crash here would be a 500 on save — a strictly
        // worse outcome than the 422 this class exists to produce.
        $blocks = is_array($definition['blocks'] ?? null) ? $definition['blocks'] : [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }
            if (($block['enabled'] ?? true) === false) {
                continue;
            }

            $blockId = (string) ($block['id'] ?? '?');

            foreach (['i18n', 'i18n_narrow'] as $table) {
                $strings = is_array($block[$table] ?? null) ? $block[$table] : [];

                foreach ($locales as $locale) {
                    $text = $strings[$locale] ?? null;
                    if (! is_string($text) || $text === '') {
                        continue;
                    }

                    $path = "blocks.{$blockId}.{$table}.{$locale}";

                    if ($violation = $this->checkEncodable($text, $path, $locale)) {
                        $violations[] = $violation;
                    }

                    foreach ($widths as $paperLabel => $columns) {
                        if ($columns <= 0) {
                            continue;
                        }
                        if ($violation = $this->checkFits($text, $columns, $paperLabel, $locale, $path)) {
                            $violations[] = $violation;
                        }
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Fail on a token no wrapping algorithm can rescue.
     *
     * Note what is NOT done here: the text is not simply pushed through
     * `Layout::wrapText` and checked for an over-wide line. `wrapText` ALWAYS
     * succeeds — it hard-splits an over-long token character by character, so
     * nothing ever overflows and such a check would pass everything. That is
     * correct behaviour at print time (TR-14/TR-20: a template problem must
     * never stop a sale) and useless as a publish gate.
     *
     * The gate's question is different: will this text still be READABLE. A
     * token wider than the paper gets chopped mid-word on every slip — a URL
     * broken across three lines, a product code split in half — and only the
     * author can fix that, which is exactly why it is caught in front of them.
     *
     * The measurement is `Layout::displayWidth`, shared with the renderer. The
     * probe this replaced kept its own width table, which measured emoji as
     * one column where the printer uses two.
     *
     * @return array{code: string, path: string, message: string}|null
     */
    private function checkFits(string $text, int $columns, string $paperLabel, string $locale, string $path): ?array
    {
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $width = Layout::displayWidth($token);
            if ($width <= $columns) {
                continue;
            }

            return [
                'code' => 'RENDER_TRIAL_FAILED',
                'path' => $path,
                'message' => sprintf(
                    'Render trial failed on %s paper (%d columns, locale %s, text_mode %s): "%s" is %d columns wide and cannot be wrapped.',
                    $paperLabel,
                    $columns,
                    $locale,
                    implode('/', self::TEXT_MODES),
                    $token,
                    $width,
                ),
            ];
        }

        return null;
    }

    /**
     * Fail on a character the printer's codepage cannot represent.
     *
     * The text is encoded exactly as the renderer would and inspected for the
     * substitute byte. A source string that legitimately CONTAINS U+001A is
     * not a real case on a receipt, and treating it as one would mean writing
     * a second encoder to tell the two apart.
     *
     * @return array{code: string, path: string, message: string}|null
     */
    private function checkEncodable(string $text, string $path, string $locale): ?array
    {
        $encoded = Escpos::encodeShiftJis($text);
        if (! str_contains($encoded, self::SUBSTITUTE)) {
            return null;
        }

        $offenders = [];
        foreach (Layout::chars($text) as $char) {
            if (str_contains(Escpos::encodeShiftJis($char), self::SUBSTITUTE)) {
                $codepoint = mb_ord($char, 'UTF-8');
                $offenders[sprintf('U+%04X', $codepoint === false ? 0 : $codepoint)] = $char;
            }
        }

        $listed = [];
        foreach (array_slice($offenders, 0, 5, true) as $codepoint => $char) {
            $listed[] = sprintf('%s (%s)', $char, $codepoint);
        }

        return [
            'code' => 'RENDER_TRIAL_UNPRINTABLE_CHARACTER',
            'path' => $path,
            'message' => sprintf(
                'Render trial failed (locale %s, text_mode %s): %s cannot be printed — the thermal printer codepage (Shift_JIS) has no glyph, so %s print as a blank or a black block. Replace %s with an ASCII or Japanese equivalent.',
                $locale,
                implode('/', self::TEXT_MODES),
                implode(', ', $listed),
                count($offenders) === 1 ? 'it would' : 'they would',
                count($offenders) === 1 ? 'it' : 'them',
            ),
        ];
    }
}
