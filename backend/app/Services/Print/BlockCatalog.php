<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Services\Print\Enums\PrintTemplateKind;

/**
 * plan-053 (#1171) — read-only accessor over `config/print_blocks.php`.
 *
 * Everything that needs to know "is this block real / locked / required for
 * this kind" goes through here, so the catalog file stays the single source
 * of truth and nobody re-derives the rules from a hard-coded list.
 */
class BlockCatalog
{
    public const MUTABILITY_LOCKED = 'locked';

    public const MUTABILITY_TOGGLEABLE = 'toggleable';

    public const MUTABILITY_FREE = 'free';

    /** @return array<string, mixed> */
    public function all(): array
    {
        return (array) config('print_blocks', []);
    }

    public function schema(): string
    {
        return (string) config('print_blocks.schema', 'tempo.print.v1');
    }

    /*
     * `dialect()` (the `receiptline_dialect` envelope key) was REMOVED in
     * #2061 — see `config/print_blocks.php` for why. No accessor replaces it.
     */

    /** @return array{columns_58mm: int, columns_80mm: int} */
    public function paper(): array
    {
        /** @var array{columns_58mm: int, columns_80mm: int} $paper */
        $paper = (array) config('print_blocks.paper', ['columns_58mm' => 32, 'columns_80mm' => 48]);

        return $paper;
    }

    /** @return list<string> */
    public function sources(): array
    {
        return array_values((array) config('print_blocks.sources', []));
    }

    /** @return list<string> */
    public function paramFields(): array
    {
        return array_values((array) config('print_blocks.param_fields', []));
    }

    /** @return array<string, mixed> */
    public function imageRules(): array
    {
        return (array) config('print_blocks.image', []);
    }

    public function hasBlock(string $blockId): bool
    {
        return array_key_exists($blockId, (array) config('print_blocks.blocks', []));
    }

    /** @return array<string, mixed>|null */
    public function block(string $blockId): ?array
    {
        $block = config("print_blocks.blocks.{$blockId}");

        return is_array($block) ? $block : null;
    }

    public function mutability(string $blockId): string
    {
        return (string) ($this->block($blockId)['mutability'] ?? self::MUTABILITY_FREE);
    }

    public function isLocked(string $blockId): bool
    {
        return in_array(
            $this->mutability($blockId),
            [self::MUTABILITY_LOCKED, self::MUTABILITY_TOGGLEABLE],
            true,
        );
    }

    public function isToggleable(string $blockId): bool
    {
        return $this->mutability($blockId) === self::MUTABILITY_TOGGLEABLE;
    }

    /** The legal condition (if any) under which a toggleable block must stay on — TR-17. */
    public function requireEnabledWhen(string $blockId): ?string
    {
        $condition = $this->block($blockId)['require_enabled_when'] ?? null;

        return is_string($condition) ? $condition : null;
    }

    /** @return list<string> */
    public function editableProps(string $blockId): array
    {
        return array_values((array) ($this->block($blockId)['editable_props'] ?? []));
    }

    /** @return list<string>|null allowed values for an enumerated prop */
    public function propEnum(string $blockId, string $prop): ?array
    {
        $enum = $this->block($blockId)['prop_enums'][$prop] ?? null;

        return is_array($enum) ? array_values($enum) : null;
    }

    /** The ORDERED block ids a kind is composed of (also its allow-list). */
    public function kindBlocks(PrintTemplateKind|string $kind): array
    {
        $value = $kind instanceof PrintTemplateKind ? $kind->value : $kind;

        return array_values((array) config("print_blocks.kinds.{$value}.blocks", []));
    }

    /** @return list<string> */
    public function requiredBlocks(PrintTemplateKind|string $kind): array
    {
        $value = $kind instanceof PrintTemplateKind ? $kind->value : $kind;

        return array_values((array) config("print_blocks.kinds.{$value}.required", []));
    }

    public function hasKind(string $kind): bool
    {
        return is_array(config("print_blocks.kinds.{$kind}"))
            && PrintTemplateKind::tryFrom($kind) !== null;
    }

    /**
     * The WHOLE catalog of one kind, in the shape the template editors consume.
     *
     * #2043 — this exists so there is exactly ONE assembly of the catalog
     * payload. Before it, HQ built the shape inline in its controller and the
     * shop surface had no catalog endpoint at all, so admin-web kept a
     * hand-copied mirror of this file (`PRINT_BLOCK_MUTABILITY`,
     * `PRINT_BLOCK_EDITABLE_PROPS`, `PRINT_PARAM_FIELDS`, `PRINT_SOURCES`,
     * `PRINT_ITEM_COLUMNS`) for the shop editor to read. That mirror drifted
     * four times (#1181 ×2, #2000, #2040) and every drift was silent: the
     * editor simply drew no control for a block or no checkbox for a field.
     *
     * `editable_props` and `prop_enums` are here for the same reason the other
     * four keys are: they decide which controls the editor draws, and publish
     * rejects anything outside them (`PROP_NOT_EDITABLE`). A client that has to
     * guess them will guess wrong eventually.
     *
     * Everything is keyed by the kind's OWN blocks, so a client never learns
     * about a block this slip cannot carry.
     *
     * @return array{
     *     blocks: list<string>,
     *     required: list<string>,
     *     sources: list<string>,
     *     param_fields: list<string>,
     *     mutability: array<string, string>,
     *     editable_props: array<string, list<string>>,
     *     prop_enums: array<string, array<string, list<string>>>,
     * }
     */
    public function catalogFor(PrintTemplateKind|string $kind): array
    {
        $blocks = $this->kindBlocks($kind);

        $mutability = [];
        $editableProps = [];
        $propEnums = [];

        foreach ($blocks as $blockId) {
            $mutability[$blockId] = $this->mutability($blockId);
            $editableProps[$blockId] = $this->editableProps($blockId);

            $enums = $this->block($blockId)['prop_enums'] ?? null;
            if (is_array($enums) && $enums !== []) {
                $propEnums[$blockId] = array_map(
                    static fn ($values): array => array_values((array) $values),
                    $enums,
                );
            }
        }

        return [
            'blocks' => $blocks,
            'required' => $this->requiredBlocks($kind),
            'sources' => $this->sources(),
            'param_fields' => $this->paramFields(),
            'mutability' => $mutability,
            'editable_props' => $editableProps,
            'prop_enums' => $propEnums,
        ];
    }

    /** The locked/toggleable block ids of a kind, in catalog order (TR-16 order check). */
    public function lockedBlockOrder(PrintTemplateKind|string $kind): array
    {
        return array_values(array_filter(
            $this->kindBlocks($kind),
            fn (string $blockId): bool => $this->isLocked($blockId),
        ));
    }
}
