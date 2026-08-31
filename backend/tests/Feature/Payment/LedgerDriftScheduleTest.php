<?php

/**
 * The money invariant every settlement path must preserve is
 * `customer_orders.paid_amount === OrderPayment::netCollectedForOrder()`, and
 * `payments:check-ledger-drift` is the auditor for it.
 *
 * It ran only when somebody remembered to type it — the one payments command
 * with no schedule, beside six siblings that have one. A cached total that has
 * drifted from the ledger neither heals nor announces itself; it surfaces at
 * reconciliation, long after the shift that produced it.
 *
 * Scheduling it alone would not have been enough. The command reported through
 * `$this->info()` / `$this->error()`, which reach a terminal — so on a cron run
 * the finding would have gone to a log nobody reads. DevOps alerting matches
 * ERROR entries by their `[...]` tag (see CheckTillsSchedulerFreshness's
 * docblock), so both halves are asserted here: it runs, and when it finds
 * something it says so where someone will hear it.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

it('is scheduled, not left to somebody remembering', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'payments:check-ledger-drift'));

    expect($event)->not->toBeNull('payments:check-ledger-drift is not scheduled')
        // A drift scan walks orders; a second copy starting on top of a slow one
        // helps nobody.
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('reports drift where alerting can see it, not just to a terminal', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
    ]);

    // paid_amount claims 5000; the ledger holds 3000. Exactly the shape the
    // workstation bug in #1251 produced — a cached total inflated past what was
    // actually captured.
    $order = CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'total_amount' => 5000,
        'paid_amount' => 5000,
    ]);
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'amount' => 3000,
        'status' => 'succeeded',
    ]);

    $tagged = false;
    Log::shouldReceive('error')
        ->withArgs(function (string $message, array $context = []) use (&$tagged): bool {
            if (str_starts_with($message, '[payments.ledger_drift]')) {
                $tagged = true;
            }

            return true;
        })
        ->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $this->artisan('payments:check-ledger-drift')->assertExitCode(1);

    expect($tagged)->toBeTrue(
        'drift was found but never logged with the [payments.ledger_drift] tag, so alerting cannot match it',
    );
});
