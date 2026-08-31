<?php

declare(strict_types=1);

/**
 * #1204 / #1206 — the relay half of the reconciliation outbox.
 *
 * Pinned here:
 *   - stranded_charge / overpayment_rejected are NOT settled automatically —
 *     moving real money back to a customer is not the relay's call to make;
 *   - a crashed claim comes back;
 *   - and ageing raises the alert, which is the only mechanism the
 *     human-resolved types have.
 *
 * (The `return_invoice` settle path was retired with #1779 — 赤伝 no longer
 * persists — so the relay now only recovers stuck claims and alerts on age.)
 */

use App\Models\MoneyReconciliationTask;
use App\Services\Payment\Reconciliation\MoneyReconciliationQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

function mrTask(array $attrs = []): MoneyReconciliationTask
{
    return MoneyReconciliationTask::create(array_merge([
        'task_type' => MoneyReconciliationQueue::TYPE_RETURN_INVOICE,
        'subject_type' => MoneyReconciliationQueue::SUBJECT_ORDER_PAYMENT,
        'subject_id' => (string) Str::uuid(),
        'organization_id' => (string) Str::uuid(),
        'branch_id' => null,
        'payload' => ['customer_order_id' => (string) Str::uuid(), 'sale_return_amount' => 1000.0],
        'status' => 'pending',
        'attempts' => 0,
        'next_retry_at' => now()->subMinute(),
    ], $attrs));
}

/**
 * Force created_at past Eloquent, which stamps its own on create() and would
 * otherwise leave every "aged" row one second old — a fixture that silently
 * does not apply, and a stale-alert test that passes for the wrong reason.
 */
function mrAge(MoneyReconciliationTask $task, string $when): MoneyReconciliationTask
{
    MoneyReconciliationTask::query()->whereKey($task->getKey())->update(['created_at' => $when]);

    return $task->fresh();
}

it('does not touch a stranded charge — moving money back is not the relay decision', function () {
    // plan-054 D5 records the same ruling for PayPay: the hand-off is to the
    // operator. The row makes the debt durable and countable; the alert makes
    // sure it is seen.
    $task = mrTask([
        'task_type' => MoneyReconciliationQueue::TYPE_STRANDED_CHARGE,
        'payload' => ['amount' => 5000.0, 'gateway' => 'stripe'],
    ]);

    $this->artisan('payments:redrive-reconciliation')->assertSuccessful();

    expect($task->fresh()->status)->toBe('pending')
        ->and((int) $task->fresh()->attempts)->toBe(0);
});

it('returns a crashed claim to pending', function () {
    $task = mrTask([
        'status' => 'claimed',
        'claimed_at' => now()->subHours(2),
    ]);

    $this->artisan('payments:redrive-reconciliation')->assertSuccessful();

    expect($task->fresh()->status)->not->toBe('claimed');
});

it('leaves a fresh claim alone', function () {
    // Still inside the timeout: that is work in progress, not a crash.
    $task = mrTask([
        'status' => 'claimed',
        'claimed_at' => now(),
    ]);

    $this->artisan('payments:redrive-reconciliation')->assertSuccessful();

    expect($task->fresh()->status)->toBe('claimed');
});

it('alerts on an aged row, tagged so the alerting rules can match it', function () {
    mrAge(
        mrTask(['task_type' => MoneyReconciliationQueue::TYPE_STRANDED_CHARGE]),
        now()->subDays(2)->toDateTimeString(),
    );

    // ERROR + a `[payments.reconcile]` prefix is the documented alerting
    // contract — a line without the tag is written and matched by nothing,
    // which is the failure #1204 and #1206 were both about.
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')
        ->atLeast()->once()
        ->withArgs(fn (string $message, array $context = []): bool => str_starts_with($message, '[payments.reconcile] reconciliation_stale_pending')
            && ($context['count'] ?? 0) >= 1);
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $this->artisan('payments:redrive-reconciliation')->assertSuccessful();
});

it('writes nothing on --dry-run', function () {
    $task = mrTask([
        'status' => 'claimed',
        'claimed_at' => now()->subHours(2),
    ]);

    $this->artisan('payments:redrive-reconciliation --dry-run')->assertSuccessful();

    expect($task->fresh()->status)->toBe('claimed');
});
