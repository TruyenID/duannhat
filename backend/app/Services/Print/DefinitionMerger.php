<?php

declare(strict_types=1);

namespace App\Services\Print;

/**
 * plan-053 (#1171) — the FIELD-WISE merge of the three layers (TR-02).
 *
 * The naive alternative — "the highest layer that exists wins wholesale" —
 * is what makes centrally managed templates unusable in practice: a shop that
 * changed its footer once would be frozen on that day's brand layout forever,
 * and HQ pushing a new tax block would silently not reach it. So an overlay
 * contributes ONLY the fields it actually sets:
 *
 *   - top-level keys (schema, paper…) merge by key;
 *   - `blocks` merge by block ID: shared ids keep the BASE order and take the
 *     overlay's props one by one; ids the base does not have are appended.
 *
 * Base order is authoritative on purpose: it is how an overlay is prevented
 * from reordering the compliance blocks (TR-16) even before the validator
 * looks at it.
 *
 * The shop layer is additionally FILTERED through the brand's `shop_editable`
 * allow-list before merging. Filtering at RESOLVE time (not at write time) is
 * what gives TR-04 its behaviour for free: when a brand narrows the allow-list
 * the shop's override of the removed field stops applying immediately, yet the
 * stored row is untouched — widen the list again and the override comes back
 * to life.
 */
class DefinitionMerger
{
    /**
     * Merge an overlay definition onto a base definition, field by field.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    public function merge(array $base, array $overlay): array
    {
        $merged = $base;

        foreach ($overlay as $key => $value) {
            if ($key === 'blocks') {
                continue;
            }
            $merged[$key] = is_array($value) && is_array($base[$key] ?? null)
                ? array_replace_recursive($base[$key], $value)
                : $value;
        }

        if (array_key_exists('blocks', $overlay)) {
            $merged['blocks'] = $this->mergeBlocks(
                is_array($base['blocks'] ?? null) ? $base['blocks'] : [],
                is_array($overlay['blocks']) ? $overlay['blocks'] : [],
            );
        }

        return $merged;
    }

    /**
     * Keep only the parts of a definition the allow-list permits (TR-03/TR-04).
     *
     * A path is either a block id (`footer_text` — any prop of that block) or
     * `blockId.prop` (`qr_block.enabled` — that prop only). A path that names
     * no block is treated as a top-level definition key (`paper`).
     *
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $allowedPaths
     * @return array<string, mixed> a definition carrying ONLY allowed fields
     */
    public function filterToAllowList(array $definition, array $allowedPaths): array
    {
        $blockPaths = [];   // block id => true (whole block) | list<string> props
        $topLevel = [];

        foreach ($allowedPaths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            [$head, $prop] = array_pad(explode('.', $path, 2), 2, null);

            $isBlock = collect($definition['blocks'] ?? [])
                ->contains(fn ($block) => is_array($block) && ($block['id'] ?? null) === $head);

            if (! $isBlock && array_key_exists($head, $definition) && $head !== 'blocks') {
                $topLevel[$head] = true;

                continue;
            }

            if ($prop === null) {
                $blockPaths[$head] = true;
            } elseif (($blockPaths[$head] ?? null) !== true) {
                $blockPaths[$head] = array_values(array_unique([
                    ...(array) ($blockPaths[$head] ?? []),
                    $prop,
                ]));
            }
        }

        $filtered = [];
        foreach (array_keys($topLevel) as $key) {
            $filtered[$key] = $definition[$key];
        }

        $blocks = [];
        foreach ($definition['blocks'] ?? [] as $block) {
            if (! is_array($block) || ! isset($block['id'])) {
                continue;
            }
            $allowed = $blockPaths[$block['id']] ?? null;
            if ($allowed === null) {
                continue;
            }
            if ($allowed === true) {
                $blocks[] = $block;

                continue;
            }
            $kept = ['id' => $block['id']];
            foreach ($allowed as $prop) {
                if (array_key_exists($prop, $block)) {
                    $kept[$prop] = $block[$prop];
                }
            }
            // A block reduced to nothing but its id contributes nothing.
            if (count($kept) > 1) {
                $blocks[] = $kept;
            }
        }

        if ($blocks !== []) {
            $filtered['blocks'] = $blocks;
        }

        return $filtered;
    }

    /**
     * The paths an overlay definition actually touches, relative to a base —
     * used to tell a shop WHICH of its edits fall outside the allow-list
     * (TR-03) and to render "this shop overrides 3 things" in admin (TR-02).
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return list<string>
     */
    public function changedPaths(array $base, array $overlay): array
    {
        $paths = [];

        foreach ($overlay as $key => $value) {
            if ($key === 'blocks') {
                continue;
            }
            if (! array_key_exists($key, $base) || $base[$key] !== $value) {
                $paths[] = $key;
            }
        }

        $baseBlocks = [];
        foreach ($base['blocks'] ?? [] as $block) {
            if (is_array($block) && isset($block['id'])) {
                $baseBlocks[$block['id']] = $block;
            }
        }

        foreach ($overlay['blocks'] ?? [] as $block) {
            if (! is_array($block) || ! isset($block['id'])) {
                continue;
            }
            $id = (string) $block['id'];
            $baseBlock = $baseBlocks[$id] ?? null;

            if ($baseBlock === null) {
                $paths[] = $id;

                continue;
            }
            foreach ($block as $prop => $value) {
                if ($prop === 'id') {
                    continue;
                }
                if (! array_key_exists($prop, $baseBlock) || $baseBlock[$prop] !== $value) {
                    $paths[] = "{$id}.{$prop}";
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<mixed>  $baseBlocks
     * @param  list<mixed>  $overlayBlocks
     * @return list<array<string, mixed>>
     */
    private function mergeBlocks(array $baseBlocks, array $overlayBlocks): array
    {
        $overlayById = [];
        foreach ($overlayBlocks as $block) {
            if (is_array($block) && isset($block['id'])) {
                $overlayById[(string) $block['id']] = $block;
            }
        }

        $merged = [];
        $consumed = [];

        foreach ($baseBlocks as $block) {
            if (! is_array($block) || ! isset($block['id'])) {
                continue;
            }
            $id = (string) $block['id'];
            if (isset($overlayById[$id])) {
                $block = array_replace($block, $overlayById[$id]);
                $consumed[$id] = true;
            }
            $merged[] = $block;
        }

        // Blocks the base does not carry (a brand adding a block the system
        // default leaves out) append in overlay order.
        foreach ($overlayBlocks as $block) {
            if (! is_array($block) || ! isset($block['id'])) {
                continue;
            }
            $id = (string) $block['id'];
            if (! isset($consumed[$id]) && ! $this->hasBlock($merged, $id)) {
                $merged[] = $block;
            }
        }

        return $merged;
    }

    /** @param list<array<string, mixed>> $blocks */
    private function hasBlock(array $blocks, string $id): bool
    {
        foreach ($blocks as $block) {
            if (($block['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }
}
