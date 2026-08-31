<?php

declare(strict_types=1);

namespace App\Services\Print;

/**
 * plan-053 (#1171) — TR-31: "what is different between June's receipt and
 * July's?" must be answerable from the history screen, not by diffing two JSON
 * blobs by eye.
 *
 * Produces a flat, path-addressed change list — added / removed / changed —
 * over the same paths {@see DefinitionMerger} merges by, so a diff entry and
 * an allow-list entry are always the same kind of string.
 */
class DefinitionDiff
{
    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<array{path: string, op: string, from: mixed, to: mixed}>
     */
    public function between(array $from, array $to): array
    {
        $changes = [];

        foreach (array_keys($from + $to) as $key) {
            if ($key === 'blocks') {
                continue;
            }
            $before = $from[$key] ?? null;
            $after = $to[$key] ?? null;
            if ($before === $after) {
                continue;
            }
            $changes[] = [
                'path' => (string) $key,
                'op' => $this->op(array_key_exists($key, $from), array_key_exists($key, $to)),
                'from' => $before,
                'to' => $after,
            ];
        }

        $fromBlocks = $this->indexBlocks($from);
        $toBlocks = $this->indexBlocks($to);

        foreach (array_keys($fromBlocks + $toBlocks) as $id) {
            $before = $fromBlocks[$id] ?? null;
            $after = $toBlocks[$id] ?? null;

            if ($before === null || $after === null) {
                $changes[] = [
                    'path' => (string) $id,
                    'op' => $before === null ? 'added' : 'removed',
                    'from' => $before,
                    'to' => $after,
                ];

                continue;
            }

            foreach (array_keys($before + $after) as $prop) {
                if ($prop === 'id') {
                    continue;
                }
                $b = $before[$prop] ?? null;
                $a = $after[$prop] ?? null;
                if ($b === $a) {
                    continue;
                }
                $changes[] = [
                    'path' => "{$id}.{$prop}",
                    'op' => $this->op(array_key_exists($prop, $before), array_key_exists($prop, $after)),
                    'from' => $b,
                    'to' => $a,
                ];
            }
        }

        // Block ORDER is meaning on a receipt — a reorder with no prop change
        // would otherwise diff as "nothing happened".
        if (array_keys($fromBlocks) !== array_keys($toBlocks)) {
            $changes[] = [
                'path' => 'blocks.order',
                'op' => 'changed',
                'from' => array_keys($fromBlocks),
                'to' => array_keys($toBlocks),
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, array<string, mixed>>
     */
    private function indexBlocks(array $definition): array
    {
        $out = [];
        foreach ($definition['blocks'] ?? [] as $block) {
            if (is_array($block) && isset($block['id']) && is_string($block['id'])) {
                $out[$block['id']] = $block;
            }
        }

        return $out;
    }

    private function op(bool $inFrom, bool $inTo): string
    {
        return match (true) {
            ! $inFrom => 'added',
            ! $inTo => 'removed',
            default => 'changed',
        };
    }
}
