<?php

use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\ApproveOrderItemRefundCommand;

/*
 * #2254 — the command was uninstantiable: `$reason` was a promoted property on a
 * `readonly` class while the constructor body re-assigned it through
 * `safeToken()`, so every `new` threw `Cannot modify readonly property` and both
 * item-refund endpoints 500'd. Nothing in the suite touched the constructor, so
 * the whole suite stayed green. These tests construct it for real — a refactor
 * that promotes `$reason` again turns them red.
 */

function refundCommandUuid(int $suffix): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $suffix);
}

function refundCommandContext(string $idempotency = 'refund-idem-1'): MutationContext
{
    return new MutationContext(
        refundCommandUuid(1),
        refundCommandUuid(2),
        'refund-correlation-1',
        $idempotency,
        1,
    );
}

it('constructs and keeps every field the endpoints pass', function () {
    $command = new ApproveOrderItemRefundCommand(
        refundCommandContext(),
        refundCommandUuid(10),
        refundCommandUuid(11),
        1.0,
        'workstation refund',
    );

    expect($command->orderId)->toBe(refundCommandUuid(10))
        ->and($command->itemId)->toBe(refundCommandUuid(11))
        ->and($command->quantity)->toBe(1.0)
        ->and($command->reason)->toBe('workstation refund')
        ->and($command->refundLineId)->toBeNull();
});

it('sanitizes the reason through safeToken instead of storing it raw', function () {
    $command = new ApproveOrderItemRefundCommand(
        refundCommandContext('refund-idem-2'),
        refundCommandUuid(12),
        refundCommandUuid(13),
        0.5,
        "  \t khách trả lại món \n ",
    );

    // safeToken() trims — proof the body ran and the value is not the raw input.
    expect($command->reason)->toBe('khách trả lại món');
});

it('rejects a reason safeToken refuses', function (string $reason) {
    expect(fn () => new ApproveOrderItemRefundCommand(
        refundCommandContext('refund-idem-reject'),
        refundCommandUuid(14),
        refundCommandUuid(15),
        1.0,
        $reason,
    ))->toThrow(InvalidArgumentException::class, 'reason');
})->with([
    'blank' => ['   '],
    'control character' => ["bad\x00reason"],
    'over 500 chars' => [str_repeat('a', 501)],
]);

it('accepts fractional quantities and rejects non-positive ones', function () {
    // Weight-sold lines refund fractionally — an int cast silently truncated 0.5.
    expect((new ApproveOrderItemRefundCommand(
        refundCommandContext('refund-idem-frac'),
        refundCommandUuid(16),
        refundCommandUuid(17),
        0.25,
        'partial',
    ))->quantity)->toBe(0.25);

    expect(fn () => new ApproveOrderItemRefundCommand(
        refundCommandContext('refund-idem-zero'),
        refundCommandUuid(18),
        refundCommandUuid(19),
        0.0,
        'zero',
    ))->toThrow(InvalidArgumentException::class, 'Refund quantity must be positive.');
});

it('normalizes the workstation-supplied refund line id', function () {
    $command = new ApproveOrderItemRefundCommand(
        refundCommandContext('refund-idem-line'),
        refundCommandUuid(20),
        refundCommandUuid(21),
        1.0,
        'queue retry',
        strtoupper(refundCommandUuid(22)),
    );

    expect($command->refundLineId)->toBe(refundCommandUuid(22));
});

it('produces a stable mutation fingerprint over all its fields', function () {
    $build = fn (string $reason) => new ApproveOrderItemRefundCommand(
        refundCommandContext('refund-idem-fp'),
        refundCommandUuid(23),
        refundCommandUuid(24),
        1.0,
        $reason,
    );

    expect($build('same')->mutationFingerprint())->toBe($build('same')->mutationFingerprint())
        ->and($build('same')->mutationFingerprint())->not->toBe($build('different')->mutationFingerprint());
});
