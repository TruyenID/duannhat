<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use App\Services\Print\Enums\PrintTemplateKind;

/**
 * plan-053 M5 (#1171) TR-32 — the SVG preview of a slip.
 *
 * SVG rather than HTML or a PNG, for three reasons that all come back to the
 * same thing — a receipt is a fixed-pitch grid:
 *
 *  - a thermal slip IS a monospace grid, and SVG lets each glyph be placed at
 *    an exact column so the preview breaks lines precisely where the printer
 *    does. HTML would re-flow to the browser's font metrics and quietly lie;
 *  - it scales without resampling, so a 32-column slip and a 48-column slip
 *    look like what they are (narrower paper, same glyph size) instead of the
 *    same width at different resolutions;
 *  - it needs no image toolchain on the server and no canvas in the client.
 *
 * The output is deliberately self-contained and inert: no external font, no
 * script, no remote reference. It is served to an admin UI and may be embedded
 * anywhere, so it must not be able to fetch anything.
 */
final class SvgRenderer
{
    /** Monospace advance width in user units — 8×16 is a thermal cell's ratio. */
    private const CELL_W = 8.0;

    private const LINE_H = 16.0;

    private const PAD = 12.0;

    private const FONT_STACK = "'M PLUS 1 Code','Noto Sans Mono CJK JP','DejaVu Sans Mono',monospace";

    public function __construct(private readonly SlipComposer $composer) {}

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>|null  $sample
     */
    public function render(
        array $definition,
        PrintTemplateKind $kind,
        string $locale,
        int $columns,
        ?array $sample = null,
    ): string {
        $lines = $this->composer->compose($definition, $kind, $locale, $columns, $sample);

        $width = self::PAD * 2 + $columns * self::CELL_W;
        // A blank tail so the slip does not end flush against its own edge —
        // the same courtesy the physical feed-before-cut gives.
        $height = self::PAD * 2 + max(count($lines) + 2, 6) * self::LINE_H;

        $body = '';
        $y = self::PAD + self::LINE_H;

        foreach ($lines as $line) {
            $text = $line['text'];
            if (trim($text) !== '') {
                $x = self::PAD + $this->indentColumns($text, $line['align'], $columns) * self::CELL_W;
                $body .= sprintf(
                    '<text x="%s" y="%s" class="%s">%s</text>',
                    $this->num($x),
                    $this->num($y),
                    $this->classFor($line),
                    $this->escape(ltrim($text) === $text ? $text : $text),
                );
            }
            $y += self::LINE_H;
        }

        return $this->document($width, $height, $columns, $body);
    }

    /**
     * Leading columns for an alignment.
     *
     * Centring is computed against the CONTENT width rather than delegated to
     * a text-anchor, mirroring the renderer: an authored line must sit inside
     * the same left margin as every other line instead of drifting relative to
     * them.
     */
    private function indentColumns(string $text, string $align, int $columns): int
    {
        $w = Layout::displayWidth($text);

        return match ($align) {
            SlipComposer::ALIGN_CENTER => (int) max(intdiv($columns - $w, 2), 0),
            SlipComposer::ALIGN_RIGHT => max($columns - $w, 0),
            default => 0,
        };
    }

    /** @param array{bold: bool, placeholder: bool} $line */
    private function classFor(array $line): string
    {
        $classes = ['l'];
        if ($line['bold']) {
            $classes[] = 'b';
        }
        if ($line['placeholder']) {
            $classes[] = 'p';
        }

        return implode(' ', $classes);
    }

    private function document(float $width, float $height, int $columns, string $body): string
    {
        /*
         * `xml:space="preserve"` and `white-space: pre` are both required:
         * every column position on a receipt is made of literal spaces, and an
         * SVG renderer that collapses runs of whitespace would silently
         * left-shift every right-aligned price.
         */
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%s" height="%s" viewBox="0 0 %s %s" '.
            'role="img" aria-label="Print template preview, %d columns" xml:space="preserve">'.
            '<style>'.
            '.bg{fill:#ffffff}'.
            '.l{font-family:%s;font-size:13px;fill:#1a1a1a;white-space:pre;dominant-baseline:alphabetic}'.
            '.b{font-weight:700}'.
            '.p{fill:#8a8a8a;font-style:italic}'.
            '@media (prefers-color-scheme: dark){.bg{fill:#f5f5f5}}'.
            '</style>'.
            '<rect class="bg" x="0" y="0" width="%s" height="%s"/>'.
            '%s</svg>',
            $this->num($width),
            $this->num($height),
            $this->num($width),
            $this->num($height),
            $columns,
            self::FONT_STACK,
            $this->num($width),
            $this->num($height),
            $body,
        );
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /**
     * Escape for SVG text content.
     *
     * The brand authors this copy, so it is untrusted input that ends up in a
     * document the admin UI embeds. `htmlspecialchars` with ENT_XML1 covers
     * the five XML entities; the substitution below removes control characters,
     * which are not legal in XML at all and would make the whole document fail
     * to parse rather than merely look wrong.
     */
    private function escape(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;

        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
