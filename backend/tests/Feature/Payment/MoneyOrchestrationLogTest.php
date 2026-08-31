<?php

declare(strict_types=1);

/**
 * #1244 — nineteen ERROR-level money events wrote only to the
 * `payment_orchestration` channel, a standalone daily file that is not part of
 * `stack`. The documented alerting path reads ERROR entries tagged `[...]` from
 * `LOG_CHANNEL=stack`, so none of them could reach anyone, and twelve carried no
 * tag either.
 *
 * The arch gate beside this one proves no call site bypasses the helper. This
 * proves the helper actually does what the gate assumes.
 */

use App\Support\Logging\MoneyOrchestrationLog;
use Illuminate\Support\Facades\Log;

it('writes the failure to the domain channel AND the default one', function () {
    // Two separate expectations rather than a spy: the whole defect was that one
    // of these two destinations was missing, so they have to be distinguishable.
    $domain = Mockery::mock();
    $domain->shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === '[payments.stranded] some_money_event'
            && $context['payment_id'] === 'pay_1');

    Log::shouldReceive('channel')
        ->with('payment_orchestration')
        ->once()
        ->andReturn($domain);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === '[payments.stranded] some_money_event'
            && $context['payment_id'] === 'pay_1');

    MoneyOrchestrationLog::error(
        MoneyOrchestrationLog::TAG_STRANDED,
        'some_money_event',
        ['payment_id' => 'pay_1'],
    );
});

it('puts the tag at the start of the message, where alerting matches', function () {
    // A tag in the middle is not a tag. The alerting rules anchor on the prefix.
    $captured = null;

    $domain = Mockery::mock();
    $domain->shouldReceive('error')->andReturnNull();
    Log::shouldReceive('channel')->andReturn($domain);
    Log::shouldReceive('error')->andReturnUsing(function (string $message) use (&$captured) {
        $captured = $message;
    });

    MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_qr_create_failed');

    expect($captured)->toStartWith('[payments.paypay] ');
});

it('keeps every tag in the closed set matching the alerting prefix shape', function () {
    // A typo in a tag is invisible at runtime and silently unmatchable, which is
    // the failure mode this whole issue is about.
    $tags = [
        MoneyOrchestrationLog::TAG_STRANDED,
        MoneyOrchestrationLog::TAG_PAYPAY,
        MoneyOrchestrationLog::TAG_RECONCILE,
        MoneyOrchestrationLog::TAG_SETTLEMENT,
    ];

    foreach ($tags as $tag) {
        expect(preg_match('/^payments\.[a-z_]+$/', $tag))->toBe(1, "tag {$tag} is not a payments.* alerting tag");
    }
});
