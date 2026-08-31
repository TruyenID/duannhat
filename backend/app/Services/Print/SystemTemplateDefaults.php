<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Services\Print\Enums\PrintTemplateKind;

/**
 * plan-053 (#1171) — LAYER 0 (TASKS T1.4).
 *
 * Builds the system default definition of a kind from two config files:
 *   - `config/print_blocks.php`     the block ORDER (catalog = source of truth)
 *   - `config/print_templates.php`  the per-block default props + authored text
 *
 * Composing rather than storing 13 literal JSON blobs means the catalog and
 * the default can never disagree about which blocks a receipt has — the class
 * of drift that would otherwise surface as a validation failure on a brand's
 * very first publish.
 *
 * Layer 0 is deliberately CODE, not a seeded row: TR-05 requires a machine
 * that has never been online to print, and only a definition shipped with the
 * software can promise that.
 */
class SystemTemplateDefaults
{
    public function __construct(private readonly BlockCatalog $catalog) {}

    /**
     * The full system default definition of one kind.
     *
     * @return array<string, mixed>
     */
    public function forKind(PrintTemplateKind|string $kind): array
    {
        $kindValue = $kind instanceof PrintTemplateKind ? $kind->value : $kind;

        /** @var array<string, array<string, mixed>> $blockDefaults */
        $blockDefaults = (array) config('print_templates.block_defaults', []);
        /** @var array<string, array<string, mixed>> $overrides */
        $overrides = (array) config("print_templates.kind_overrides.{$kindValue}", []);

        $blocks = [];
        foreach ($this->catalog->kindBlocks($kindValue) as $blockId) {
            $block = ['id' => $blockId]
                + ($blockDefaults[$blockId] ?? ['type' => 'locked']);

            // Per-kind override wins over the shared default, field by field
            // (a kind that only renames the title keeps every other prop).
            if (isset($overrides[$blockId]) && is_array($overrides[$blockId])) {
                $block = array_replace($block, $overrides[$blockId]);
            }

            $blocks[] = $block;
        }

        // #1181: an empty `i18n` map must not reach the wire as `[]` — see
        // DefinitionNormalizer for why that made the whole definition
        // unparseable on the workstation.
        // #3082 — prop cấp MẪU, không cấp khối.
        //
        // `kind_overrides` được tra theo BLOCK ID (`$overrides[$blockId]`), nên
        // đặt `top_feed` vào đó sẽ bị coi là một khối tên "top_feed", không khớp
        // `kindBlocks()` nào, và **rơi im lặng** — đúng hình dạng #2622: khai một
        // chỗ rồi tưởng nó tới nơi. Vì thế nó có chỗ riêng, tường minh.
        //
        // Cấp mẫu là đúng ngữ nghĩa: `top_feed` nói về MÉP GIẤY, không nói về nội
        // dung khối nào; gắn vào một khối thì đổi thứ tự khối là khoảng trắng
        // chạy theo.
        /** @var array<string, mixed> $kindDefaults */
        $kindDefaults = (array) config("print_templates.kind_defaults.{$kindValue}", []);

        return DefinitionNormalizer::forTransport([
            'schema' => $this->catalog->schema(),
            'paper' => $this->catalog->paper(),
            'kind' => $kindValue,
            'blocks' => $blocks,
        ] + $kindDefaults);
    }

    /**
     * Every kind's system default, keyed by kind.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $out = [];
        foreach (PrintTemplateKind::cases() as $kind) {
            $out[$kind->value] = $this->forKind($kind);
        }

        return $out;
    }
}
