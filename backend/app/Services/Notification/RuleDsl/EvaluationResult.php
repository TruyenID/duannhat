<?php

namespace App\Services\Notification\RuleDsl;

/**
 * Plan-023 M7 T7.2 — value object returned by RuleEvaluatorService.
 *
 * Captures the boolean outcome + a per-leaf trace. The trace is what
 * powers the admin "why didn't this fire?" surface and what
 * EvaluateRuleJob writes to notification_rule_firings.evaluation_trace.
 */
final readonly class EvaluationResult
{
    /**
     * @param  array<int, array{field: string, op: string, expected: mixed, actual: mixed, pass: bool}>  $trace
     */
    public function __construct(
        public bool $matched,
        public array $trace,
    ) {}
}
