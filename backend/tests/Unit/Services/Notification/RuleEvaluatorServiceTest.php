<?php

/**
 * Plan-023 M7 T7.2 — RuleEvaluatorService unit coverage.
 *
 * Pure-function tests; no DB / no facades. The evaluator operates on
 * NotificationRule + Eloquent Model + a $changes array.
 */

use App\Models\NotificationRule;
use App\Services\Notification\RuleEvaluatorService;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Build a throwaway Model carrying arbitrary attributes for the
 * evaluator's data_get lookups. Reuses NotificationRule as the
 * carrier so we don't ship a one-off factory; the evaluator never
 * inspects the model's actual class beyond attribute access.
 */
function fakeModel(array $attrs): Model
{
    $m = new NotificationRule;
    $m->setRawAttributes($attrs, sync: true);

    return $m;
}

function ruleWith(array $conditions): NotificationRule
{
    $rule = new NotificationRule;
    $rule->conditions = $conditions;

    return $rule;
}

it('M7-2: AND short-circuits on first false leaf', function () {
    $rule = ruleWith([
        'combinator' => 'and',
        'children' => [
            ['field' => 'status', 'op' => '=', 'value' => 'approved'],
            ['field' => 'priority', 'op' => '=', 'value' => 'high'],
        ],
    ]);
    $model = fakeModel(['status' => 'approved', 'priority' => 'low']);

    $result = app(RuleEvaluatorService::class)->evaluate($rule, $model);

    expect($result->matched)->toBeFalse();
});

it('M7-2: OR short-circuits on first true leaf', function () {
    $rule = ruleWith([
        'combinator' => 'or',
        'children' => [
            ['field' => 'status', 'op' => '=', 'value' => 'approved'],
            ['field' => 'priority', 'op' => '=', 'value' => 'high'],
        ],
    ]);
    $model = fakeModel(['status' => 'rejected', 'priority' => 'high']);

    expect(app(RuleEvaluatorService::class)->evaluate($rule, $model)->matched)->toBeTrue();
});

it('M7-3: each op evaluates correctly', function (string $op, mixed $actual, mixed $expected, bool $shouldPass) {
    $rule = ruleWith(['field' => 'val', 'op' => $op, 'value' => $expected]);
    $model = fakeModel(['val' => $actual]);

    expect(app(RuleEvaluatorService::class)->evaluate($rule, $model)->matched)->toBe($shouldPass);
})->with([
    ['=', 'approved', 'approved', true],
    ['=', 1, '1', true],            // permissive equality
    ['!=', 'a', 'b', true],
    ['>', 100, 50, true],
    ['<', 50, 100, true],
    ['>=', 50, 50, true],
    ['in', 'b', ['a', 'b', 'c'], true],
    ['in', 'z', ['a', 'b'], false],
    ['not_in', 'z', ['a', 'b'], true],
    ['is_null', null, null, true],
    ['is_null', 0, null, false],
    ['is_not_null', 'x', null, true],
]);

it('M7-3: matches op is regex-anchored when delimiters present, literal-quoted otherwise', function () {
    $delim = ruleWith(['field' => 'name', 'op' => 'matches', 'value' => '/^Recipe-/']);
    $literal = ruleWith(['field' => 'name', 'op' => 'matches', 'value' => 'Recipe-']);

    $hit = fakeModel(['name' => 'Recipe-123']);
    $miss = fakeModel(['name' => 'XRecipe-123']);

    $svc = app(RuleEvaluatorService::class);
    expect($svc->evaluate($delim, $hit)->matched)->toBeTrue();
    expect($svc->evaluate($delim, $miss)->matched)->toBeFalse();
    expect($svc->evaluate($literal, $hit)->matched)->toBeTrue();   // literal substring works either way
});

it('M7-3: changed / changed_to / changed_from inspect the changes array', function () {
    $rule = ruleWith(['field' => 'status', 'op' => 'changed_to', 'value' => 'approved']);
    $model = fakeModel(['status' => 'approved']);
    $changes = ['status' => ['pending', 'approved']];

    expect(app(RuleEvaluatorService::class)->evaluate($rule, $model, $changes)->matched)->toBeTrue();

    $ruleFrom = ruleWith(['field' => 'status', 'op' => 'changed_from', 'value' => 'pending']);
    expect(app(RuleEvaluatorService::class)->evaluate($ruleFrom, $model, $changes)->matched)->toBeTrue();

    $ruleChanged = ruleWith(['field' => 'status', 'op' => 'changed']);
    expect(app(RuleEvaluatorService::class)->evaluate($ruleChanged, $model, $changes)->matched)->toBeTrue();
    expect(app(RuleEvaluatorService::class)->evaluate($ruleChanged, $model, [])->matched)->toBeFalse();
});

it('M7-2: trace records every leaf with pass/fail', function () {
    $rule = ruleWith([
        'combinator' => 'or',
        'children' => [
            ['field' => 'a', 'op' => '=', 'value' => 1],
            ['field' => 'b', 'op' => '=', 'value' => 2],
        ],
    ]);
    $model = fakeModel(['a' => 9, 'b' => 2]);

    $result = app(RuleEvaluatorService::class)->evaluate($rule, $model);

    expect($result->matched)->toBeTrue();
    // OR short-circuits — first false then second true (the trace
    // contains both because OR records each evaluated leaf).
    expect($result->trace)->toHaveCount(2);
    expect($result->trace[0]['pass'])->toBeFalse();
    expect($result->trace[1]['pass'])->toBeTrue();
});

it('regex matcher returns false on invalid pattern instead of throwing', function () {
    $rule = ruleWith(['field' => 'name', 'op' => 'matches', 'value' => '/[unterminated/']);
    $model = fakeModel(['name' => 'anything']);

    expect(app(RuleEvaluatorService::class)->evaluate($rule, $model)->matched)->toBeFalse();
});
