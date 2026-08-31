<?php

/**
 * Plan-023 M7 T7.6 — RuleDslValidator coverage.
 */

use App\Services\Notification\RuleDsl\RuleDslValidator;

it('M7-V1: valid combinator with leaves returns no errors', function () {
    $tree = [
        'combinator' => 'and',
        'children' => [
            ['field' => 'status', 'op' => 'changed_to', 'value' => 'approved'],
            ['field' => 'priority', 'op' => 'in', 'value' => ['high', 'urgent']],
        ],
    ];
    expect(RuleDslValidator::validate($tree))->toBe([]);
});

it('M7-V2: unknown op produces an error pointing at the leaf', function () {
    $errors = RuleDslValidator::validate([
        'field' => 'x', 'op' => 'wibble', 'value' => 1,
    ]);
    expect($errors)->not->toBeEmpty();
    expect(collect($errors)->pluck('path')->all())->toContain('$.op');
});

it('M7-V3: depth > 5 rejected up-front', function () {
    $node = ['field' => 'leaf', 'op' => '=', 'value' => 1];
    // Wrap 6 deep — depth=6 should trip MAX_DEPTH=5 (root is depth 1).
    for ($i = 0; $i < 6; $i++) {
        $node = ['combinator' => 'and', 'children' => [$node]];
    }
    $errors = RuleDslValidator::validate($node);
    expect(collect($errors)->pluck('error')->implode('|'))->toContain('max depth');
});

it('M7-V4: dotted field path > 3 levels rejected', function () {
    $errors = RuleDslValidator::validate([
        'field' => 'a.b.c.d.e', 'op' => '=', 'value' => 1,
    ]);
    expect(collect($errors)->pluck('error')->implode('|'))->toContain('exceeds max depth');
});

it('M7-V5: op requiring a value rejects when value is missing', function () {
    $errors = RuleDslValidator::validate(['field' => 'x', 'op' => '=']);
    expect(collect($errors)->pluck('error')->implode('|'))->toContain('requires a value');
});

it('M7-V6: combinator other than and/or rejected', function () {
    $errors = RuleDslValidator::validate(['combinator' => 'xor', 'children' => []]);
    expect(collect($errors)->pluck('path')->all())->toContain('$.combinator');
});

it('M7-V7: more than MAX_LEAVES leaves rejected', function () {
    $children = [];
    for ($i = 0; $i <= RuleDslValidator::MAX_LEAVES; $i++) {
        $children[] = ['field' => 'a', 'op' => '=', 'value' => $i];
    }
    $tree = ['combinator' => 'or', 'children' => $children];
    $errors = RuleDslValidator::validate($tree);
    expect(collect($errors)->pluck('error')->implode('|'))->toContain('max '.RuleDslValidator::MAX_LEAVES);
});

it('M7-V8: non-array root rejected', function () {
    expect(RuleDslValidator::validate(null))->not->toBeEmpty();
    expect(RuleDslValidator::validate('hello'))->not->toBeEmpty();
});
