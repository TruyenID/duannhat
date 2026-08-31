<?php

namespace App\Services\Notification\RuleDsl;

/**
 * Plan-023 M7 T7.6 — recursive DSL shape check for notification rule
 * conditions.
 *
 * Returns an array of `['path' => 'human path', 'error' => '...']`
 * entries. Empty array = valid. Hostile / malformed payloads bail at
 * the first hard violation (e.g. infinite recursion guarded by
 * MAX_DEPTH) but otherwise report every leaf that's wrong so the
 * admin sees the whole picture.
 *
 * Validation envelope:
 *   - MAX_DEPTH = 5             — nested combinator trees beyond this
 *                                 are rejected up-front (pathological
 *                                 nesting blows up the evaluator's
 *                                 stack on hot paths).
 *   - MAX_LEAVES = 50           — flat count cap. 50 is generous;
 *                                 real rules sit around 3-5 leaves.
 *   - MAX_FIELD_DEPTH = 3       — dotted field paths can traverse
 *                                 `model.relation.relation.attr` but
 *                                 not deeper — deeper paths force
 *                                 the evaluator to walk relations
 *                                 that emitters rarely eager-load.
 */
final class RuleDslValidator
{
    public const MAX_DEPTH = 5;

    public const MAX_LEAVES = 50;

    public const MAX_FIELD_DEPTH = 3;

    /** @var array<int, string> */
    public const SUPPORTED_OPS = [
        '=', '!=',
        '>', '<', '>=', '<=',
        'in', 'not_in',
        'is_null', 'is_not_null',
        'matches',
        'changed', 'changed_to', 'changed_from',
    ];

    /**
     * Validate a conditions tree. Returns an array of error rows; an
     * empty array means valid.
     *
     * @return array<int, array{path: string, error: string}>
     */
    public static function validate(mixed $tree): array
    {
        if (! is_array($tree)) {
            return [['path' => '$', 'error' => 'Conditions must be a JSON object.']];
        }

        $errors = [];
        $leafCount = 0;
        self::walk($tree, '$', 1, $errors, $leafCount);

        if ($leafCount > self::MAX_LEAVES) {
            $errors[] = ['path' => '$', 'error' => 'Rule has '.$leafCount.' leaves; max '.self::MAX_LEAVES.'.'];
        }

        return $errors;
    }

    /**
     * Recursive walker. Mutates $errors + $leafCount.
     *
     * @param  array<int, array{path: string, error: string}>  $errors
     */
    private static function walk(mixed $node, string $path, int $depth, array &$errors, int &$leafCount): void
    {
        if (! is_array($node)) {
            $errors[] = ['path' => $path, 'error' => 'Expected object, got '.gettype($node).'.'];

            return;
        }

        if ($depth > self::MAX_DEPTH) {
            $errors[] = ['path' => $path, 'error' => 'Nesting exceeds max depth '.self::MAX_DEPTH.'.'];

            return;
        }

        // Combinator node (and / or with children).
        if (array_key_exists('combinator', $node)) {
            $combinator = $node['combinator'];
            if (! in_array($combinator, ['and', 'or'], true)) {
                $errors[] = ['path' => $path.'.combinator', 'error' => "combinator must be 'and' or 'or'."];

                return;
            }
            if (! isset($node['children']) || ! is_array($node['children'])) {
                $errors[] = ['path' => $path.'.children', 'error' => 'children must be an array.'];

                return;
            }
            foreach ($node['children'] as $i => $child) {
                self::walk($child, $path.".children[{$i}]", $depth + 1, $errors, $leafCount);
            }

            return;
        }

        // Leaf node — field + op + (maybe) value.
        $leafCount++;

        if (! isset($node['field']) || ! is_string($node['field']) || $node['field'] === '') {
            $errors[] = ['path' => $path.'.field', 'error' => 'field must be a non-empty string.'];
        } else {
            $segments = explode('.', $node['field']);
            if (count($segments) > self::MAX_FIELD_DEPTH) {
                $errors[] = [
                    'path' => $path.'.field',
                    'error' => 'Dotted field path '.$node['field'].' exceeds max depth '.self::MAX_FIELD_DEPTH.'.',
                ];
            }
        }

        $op = $node['op'] ?? null;
        if (! is_string($op) || ! in_array($op, self::SUPPORTED_OPS, true)) {
            $errors[] = [
                'path' => $path.'.op',
                'error' => 'op must be one of: '.implode(', ', self::SUPPORTED_OPS).'.',
            ];
        }

        // Ops that take no value.
        $noValueOps = ['is_null', 'is_not_null', 'changed'];
        if (is_string($op) && ! in_array($op, $noValueOps, true)) {
            if (! array_key_exists('value', $node)) {
                $errors[] = ['path' => $path.'.value', 'error' => "op '{$op}' requires a value."];
            }
        }
    }
}
