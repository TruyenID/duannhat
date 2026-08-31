<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use App\Services\Print\Enums\PrintTemplateKind;

/**
 * plan-053 M5 (#1171) TR-32/TR-33 — turn a definition into the LINES a slip
 * would print, for preview.
 *
 * ── What this is, and what it is not ──────────────────────────────────────
 *
 * This composes a slip the way the definition describes it: block order, the
 * enabled/disabled toggles, the brand's authored text in the reader's locale,
 * alignment, bold, and wrapping at the real column count — all measured with
 * the SAME {@see Layout} the ESC/POS renderer uses, so the preview breaks its
 * lines exactly where the printer will.
 *
 * The CONTENT of engine-owned (`locked`) blocks is SAMPLE data. A preview
 * cannot show real money: there is no order, and inventing one that looked
 * authoritative would be worse than one that obviously is not. The sample is
 * the TR-33 standard basket — two tax rates so the ※ marker and the per-rate
 * block both appear, a discount, a service charge, a topping — because a
 * preview of the simple case hides the layout problems.
 *
 * So: **structure is exact, figures are illustrative.** The byte-level promise
 * belongs to the ESC/POS parity gate, not here.
 *
 * This replaces admin-web's client-side `preview-renderer.ts`. One
 * implementation on the server is the point: a second one in TypeScript is how
 * "the preview and the slip disagree" bugs are born, and the shop that suffers
 * it is the one that cannot read either.
 */
final class SlipComposer
{
    /** One rendered line and how it should be drawn. */
    public const ALIGN_LEFT = 'left';

    public const ALIGN_CENTER = 'center';

    public const ALIGN_RIGHT = 'right';

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $sample
     * @return list<array{text: string, bold: bool, align: string, block: string, placeholder: bool}>
     */
    public function compose(
        array $definition,
        PrintTemplateKind $kind,
        string $locale,
        int $columns,
        ?array $sample = null,
    ): array {
        // The locale goes DOWN with it (#2028). It used to stop here, and the
        // sample answered with a Japanese label table of its own — so an en/vi
        // preview came out in Japanese while the printer was translating fine.
        $sample ??= SampleSlipData::forKind($kind, $columns, $locale);
        $narrow = $columns < 42;

        $lines = [];
        foreach ($definition['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['enabled'] ?? true) === false) {
                continue;
            }

            $id = (string) ($block['id'] ?? '');
            $type = (string) ($block['type'] ?? 'locked');

            foreach ($this->emit($id, $type, $block, $sample, $locale, $columns, $narrow) as $line) {
                $lines[] = $line + ['block' => $id, 'placeholder' => false];
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $sample
     * @return list<array{text: string, bold: bool, align: string, placeholder?: bool}>
     */
    private function emit(
        string $id,
        string $type,
        array $block,
        array $sample,
        string $locale,
        int $columns,
        bool $narrow,
    ): array {
        return match ($type) {
            'text' => $this->emitText($block, $locale, $columns, $narrow),
            'params' => $this->emitParams($block, $sample, $columns),
            'line_items' => $this->emitItems($block, $sample, $columns),
            'qr' => [$this->placeholder('[ QR: '.(string) ($block['source'] ?? 'order_url').' ]', self::ALIGN_CENTER)],
            'image' => [$this->placeholder('[ '.(string) ($block['source'] ?? 'image').' ]', (string) ($block['align'] ?? self::ALIGN_CENTER))],
            default => $this->emitLocked($id, $sample, $columns),
        };
    }

    /**
     * Brand-authored copy, in the reader's locale, wrapped and aligned.
     *
     * @param  array<string, mixed>  $block
     * @return list<array{text: string, bold: bool, align: string}>
     */
    private function emitText(array $block, string $locale, int $columns, bool $narrow): array
    {
        $text = trim(Definition::resolveText($block, $locale, $narrow));
        if ($text === '') {
            return [];
        }

        $align = (string) ($block['align'] ?? self::ALIGN_LEFT);
        $bold = ($block['bold'] ?? false) === true;

        $out = [];
        foreach (Layout::wrapText($text, $columns) as $line) {
            $out[] = ['text' => $line, 'bold' => $bold, 'align' => $align];
        }

        return $out;
    }

    /**
     * A chosen subset of known fields — never an invented binding (TR-21).
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $sample
     * @return list<array{text: string, bold: bool, align: string}>
     */
    private function emitParams(array $block, array $sample, int $columns): array
    {
        /** @var array<string, array{label: string, value: string}> $fields */
        $fields = $sample['params'] ?? [];

        $out = [];
        foreach ((array) ($block['fields'] ?? []) as $field) {
            $entry = $fields[(string) $field] ?? null;
            if ($entry === null || $entry['value'] === '') {
                continue;
            }
            $out[] = [
                'text' => $this->row($entry['label'], $entry['value'], $columns),
                'bold' => false,
                'align' => self::ALIGN_LEFT,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $sample
     * @return list<array{text: string, bold: bool, align: string}>
     */
    private function emitItems(array $block, array $sample, int $columns): array
    {
        /** @var list<array{name: string, qty: int, unit_price: string, amount: string, mark: string}> $items */
        $items = $sample['items'] ?? [];
        $columnsWanted = array_map('strval', (array) ($block['columns'] ?? ['name', 'qty', 'amount']));
        $showUnitPrice = in_array('unit_price', $columnsWanted, true);

        $out = [];
        foreach ($items as $item) {
            $right = $showUnitPrice
                ? $item['unit_price'].'  '.$item['amount']
                : $item['amount'];

            // The name column wraps UNDER the quantity, exactly as the printed
            // item table does — continuation lines are indented to the name
            // column rather than starting at the paper edge.
            $indent = Layout::displayWidth((string) $item['qty']) + 2;
            $nameWidth = max($columns - $indent - Layout::displayWidth($right), 1);
            $wrapped = Layout::wrapNameLines($item['name'].$item['mark'], $nameWidth, max($columns - $indent, 1));

            $out[] = [
                'text' => $item['qty'].'  '.Layout::padRight($wrapped[0], $nameWidth).$right,
                'bold' => false,
                'align' => self::ALIGN_LEFT,
            ];
            foreach (array_slice($wrapped, 1) as $cont) {
                $out[] = ['text' => Layout::spaces($indent).$cont, 'bold' => false, 'align' => self::ALIGN_LEFT];
            }
        }

        return $out;
    }

    /**
     * Engine-owned content. The renderer builds these from the money/tax
     * engine; the preview shows the sample figures so the LAYOUT is honest
     * even though the numbers are not real.
     *
     * @param  array<string, mixed>  $sample
     * @return list<array{text: string, bold: bool, align: string}>
     */
    private function emitLocked(string $id, array $sample, int $columns): array
    {
        /** @var array<string, list<array{label?: string, value?: string, text?: string, bold?: bool, align?: string, indent?: int}>> $locked */
        $locked = $sample['locked'] ?? [];
        $rows = $locked[$id] ?? null;
        if ($rows === null) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $indent = (int) ($row['indent'] ?? 0);
            if (isset($row['text'])) {
                $out[] = [
                    'text' => Layout::spaces($indent).$row['text'],
                    'bold' => ($row['bold'] ?? false) === true,
                    'align' => (string) ($row['align'] ?? self::ALIGN_LEFT),
                ];

                continue;
            }
            $out[] = [
                'text' => Layout::spaces($indent).$this->row(
                    (string) ($row['label'] ?? ''),
                    (string) ($row['value'] ?? ''),
                    $columns - $indent,
                ),
                'bold' => ($row['bold'] ?? false) === true,
                'align' => self::ALIGN_LEFT,
            ];
        }

        return $out;
    }

    /** @return array{text: string, bold: bool, align: string, placeholder: bool} */
    private function placeholder(string $text, string $align): array
    {
        return ['text' => $text, 'bold' => false, 'align' => $align, 'placeholder' => true];
    }

    /**
     * "label ....... value" justified to the paper, same gap rule as the
     * renderer.
     *
     * A row with NO label is not a two-column row at all (#2036). The store
     * lines are the ones that hit this: `StoreInfoBlock::emit()` prints
     * `$ctx->encoder->line($value)` — the bare value at the left margin, no
     * label column, in all three slip families. Justifying an empty label
     * against the paper pushed 法人名 / 屋号 / address / TEL to the RIGHT edge
     * of the preview while the printer puts them at the left, which is exactly
     * the "shows a layout the printer cannot produce" failure R2 exists for,
     * only sideways instead of too wide.
     */
    private function row(string $label, string $value, int $columns): string
    {
        if ($label === '') {
            return $value;
        }

        $gap = $columns - Layout::displayWidth($label) - Layout::displayWidth($value);

        return $label.Layout::spaces(max($gap, 1)).$value;
    }
}
