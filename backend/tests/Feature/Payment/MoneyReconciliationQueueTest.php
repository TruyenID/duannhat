<?php

declare(strict_types=1);

/**
 * #1204 / #1206 — the write half of the outbox, at the level where its design
 * decisions actually live.
 *
 * Two of them can lose real money if they are wrong, so they are pinned
 * directly rather than through a call site:
 *
 *   - a stranded charge is keyed on the CHARGE, not the order. Two stranded
 *     charges on one order are two debts; keying on the order would merge them
 *     and the "never rewrite a settled payload" rule would then silently drop
 *     the second amount.
 *   - a resolved row is never reopened. A duplicate request must not hand
 *     finished work back to the relay and produce a second document or refund.
 */

use App\Models\MoneyReconciliationTask;
use App\Services\Payment\Reconciliation\MoneyReconciliationQueue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->queue = app(MoneyReconciliationQueue::class);
    $this->orgId = (string) Str::uuid();
});

it('keeps two stranded charges on one order as two separate debts', function () {
    $orderId = (string) Str::uuid();

    foreach ([['pi_first', 5000.0], ['pi_second', 250.0]] as [$intent, $amount]) {
        $this->queue->enqueue(
            MoneyReconciliationQueue::TYPE_STRANDED_CHARGE,
            MoneyReconciliationQueue::SUBJECT_PAYMENT_INTENT,
            MoneyReconciliationQueue::subjectIdForGatewayReference($intent),
            ['payment_intent_id' => $intent, 'customer_order_id' => $orderId, 'charged_amount' => $amount],
            $this->orgId,
        );
    }

    $tasks = MoneyReconciliationTask::query()
        ->where('task_type', MoneyReconciliationQueue::TYPE_STRANDED_CHARGE)
        ->get();

    // Both amounts survive. One row here would mean ¥250 of the customer's
    // money stops being counted.
    expect($tasks)->toHaveCount(2)
        // Cast: a JSON round-trip writes 250.0 back as an int, so a strict
        // compare fails on type alone — the same trap #1292 was about.
        ->and($tasks->pluck('payload.charged_amount')->map(fn ($v) => (float) $v)->sort()->values()->all())
        ->toBe([250.0, 5000.0]);
});

it('re-drives the same row when the same charge strands twice', function () {
    $subject = MoneyReconciliationQueue::subjectIdForGatewayReference('pi_replayed');

    $this->queue->enqueue(
        MoneyReconciliationQueue::TYPE_STRANDED_CHARGE,
        MoneyReconciliationQueue::SUBJECT_PAYMENT_INTENT,
        $subject,
        ['charged_amount' => 5000.0],
        $this->orgId,
        null,
        'first failure',
    );
    // A webhook replay of the same charge.
    $this->queue->enqueue(
        MoneyReconciliationQueue::TYPE_STRANDED_CHARGE,
        MoneyReconciliationQueue::SUBJECT_PAYMENT_INTENT,
        $subject,
        ['charged_amount' => 9999.0],
        $this->orgId,
        null,
        'second failure',
    );

    $tasks = MoneyReconciliationTask::query()->get();

    expect($tasks)->toHaveCount(1)
        // The settled figure stands…
        ->and((float) $tasks->first()->payload['charged_amount'])->toBe(5000.0)
        // …while the newest cause is what an operator reads.
        ->and($tasks->first()->last_error)->toBe('second failure');
});

it('never reopens a resolved task', function () {
    $subject = MoneyReconciliationQueue::subjectIdForGatewayReference('pi_done');

    $task = $this->queue->enqueue(
        MoneyReconciliationQueue::TYPE_STRANDED_CHARGE,
        MoneyReconciliationQueue::SUBJECT_PAYMENT_INTENT,
        $subject,
        ['charged_amount' => 100.0],
        $this->orgId,
    );
    $task->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution' => 'refunded by hand']);

    $this->queue->enqueue(
        MoneyReconciliationQueue::TYPE_STRANDED_CHARGE,
        MoneyReconciliationQueue::SUBJECT_PAYMENT_INTENT,
        $subject,
        ['charged_amount' => 100.0],
        $this->orgId,
        null,
        'a late duplicate',
    );

    // Still resolved, and the operator's note is intact — a second refund must
    // not be queued because a stale message arrived.
    expect($task->fresh()->status)->toBe('resolved')
        ->and($task->fresh()->resolution)->toBe('refunded by hand');
});

it('reports rather than throws when the row cannot be written', function () {
    // Every call site is a path where money has already moved. A bookkeeping
    // failure must not become an exception there.
    expect(fn () => $this->queue->enqueue(
        MoneyReconciliationQueue::TYPE_STRANDED_CHARGE,
        MoneyReconciliationQueue::SUBJECT_PAYMENT_INTENT,
        'not-a-uuid-and-far-too-long-'.str_repeat('x', 200),
        ['charged_amount' => 1.0],
        $this->orgId,
    ))->not->toThrow(Throwable::class);
});
