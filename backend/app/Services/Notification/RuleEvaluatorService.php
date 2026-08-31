<?php

namespace App\Services\Notification;

use App\Models\NotificationRule;
use App\Services\Notification\RuleDsl\EvaluationResult;
use Illuminate\Database\Eloquent\Model;

/**
 * Plan-023 M7 T7.2 — pure evaluator for the rule DSL.
 *
 * Inputs: a NotificationRule (carries conditions JSON), the firing
 * Eloquent model, and an optional `$changes` array (Eloquent
 * `$model->getChanges()` style — keys are columns that changed, values
 * are `[old, new]` tuples).
 *
 * Output: `EvaluationResult($matched, $trace)` where `$trace` is an
 * array of `{field, op, expected, actual, pass}` rows — one per leaf
 * — for the admin "why didn't this fire?" debug surface.
 *
 * Side-effect-free. The caller (EvaluateRuleJob) decides whether to
 * dispatch a Notification, log a firing row, or fall through to
 * shadow mode.
 *
 * Regex safety: `matches` ops cap PCRE backtracking via
 * `ini_set('pcre.backtrack_limit', ...)` around the call so a
 * pathological pattern can't lock the worker for seconds.
 */
final class RuleEvaluatorService
{
    public const REGEX_BACKTRACK_LIMIT = 50_000;

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     *                                                             ['column' => [oldValue, newValue]]
     */
    public function evaluate(NotificationRule $rule, Model $model, array $changes = []): EvaluationResult
    {
        $trace = [];
        $matched = $this->evaluateNode((array) ($rule->conditions ?? []), $model, $changes, $trace);

        return new EvaluationResult($matched, $trace);
    }

    /**
     * Recursive node walker. Combinator nodes short-circuit per logic
     * (AND on first false, OR on first true) — important for traces
     * to be small on rejected branches.
     *
     * @param  array<int, array{field: string, op: string, expected: mixed, actual: mixed, pass: bool}>  $trace
     */
    private function evaluateNode(array $node, Model $model, array $changes, array &$trace): bool
    {
        // Combinator node.
        if (isset($node['combinator'])) {
            $combinator = (string) $node['combinator'];
            $children = (array) ($node['children'] ?? []);

            foreach ($children as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $childResult = $this->evaluateNode($child, $model, $changes, $trace);
                if ($combinator === 'and' && ! $childResult) {
                    return false;
                }
                if ($combinator === 'or' && $childResult) {
                    return true;
                }
            }

            return $combinator === 'and';     // empty AND = true; empty OR = false
        }

        // Leaf node.
        $field = (string) ($node['field'] ?? '');
        $op = (string) ($node['op'] ?? '');
        $expected = $node['value'] ?? null;
        $actual = $this->resolveField($field, $model, $changes);

        $pass = $this->compare($op, $actual, $expected, $field, $changes);

        $trace[] = [
            'field' => $field,
            'op' => $op,
            'expected' => $expected,
            'actual' => $actual,
            'pass' => $pass,
        ];

        return $pass;
    }

    /**
     * Resolve a (possibly dotted) field path against the model.
     * `data_get` handles nulls through the chain gracefully.
     */
    private function resolveField(string $field, Model $model, array $changes): mixed
    {
        // Special-case `__changed.<col>` so the admin can read the
        // "did this change?" surface without a dedicated op.
        if (str_starts_with($field, '__changed.')) {
            $col = substr($field, strlen('__changed.'));

            return array_key_exists($col, $changes);
        }

        return data_get($model, $field);
    }

    /**
     * Compare $actual to $expected under $op semantics.
     */
    private function compare(string $op, mixed $actual, mixed $expected, string $field, array $changes): bool
    {
        return match ($op) {
            '=' => $actual === $expected || $actual == $expected,   // permissive equality for int/string
            '!=' => $actual !== $expected && $actual != $expected,
            '>' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            '<' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            '>=' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            '<=' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'in' => is_array($expected) && in_array($actual, $expected, false),
            'not_in' => is_array($expected) && ! in_array($actual, $expected, false),
            'is_null' => $actual === null,
            'is_not_null' => $actual !== null,
            'matches' => $this->safeRegex((string) $expected, (string) $actual),
            'changed' => array_key_exists($field, $changes),
            'changed_to' => array_key_exists($field, $changes)
                && ($changes[$field][1] ?? null) == $expected,
            'changed_from' => array_key_exists($field, $changes)
                && ($changes[$field][0] ?? null) == $expected,
            default => false,
        };
    }

    /**
     * PCRE matcher with backtrack cap. Returns false on invalid regex
     * + on any error; never throws.
     */
    private function safeRegex(string $pattern, string $subject): bool
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::REGEX_BACKTRACK_LIMIT);
        try {
            // Anchor as exact match unless caller already supplied delimiters.
            $hasDelimiter = strlen($pattern) >= 2
                && in_array($pattern[0], ['/', '#', '~', '@'], true);
            $expr = $hasDelimiter ? $pattern : '/'.preg_quote($pattern, '/').'/u';
            $result = @preg_match($expr, $subject);

            return $result === 1;
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }
    }
}
