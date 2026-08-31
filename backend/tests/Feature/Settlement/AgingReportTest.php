<?php

use App\Models\PaymentSettlement;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\SettlementAgingReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Support\Payment\SettlementTestFactory;

/**
 * Plan-050 M4 (T4.2) — pending-payout aging (TESTS.md: aging report).
 *
 * S-22 / #1091 — aging is DAYS ELAPSED between UTC instants, so the report
 * is identical regardless of the operator's wall clock. Frozen at three
 * timezones per the business-time test contract.
 */
afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function agingService(): SettlementAgingReportService
{
    return app(SettlementAgingReportService::class);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function pendingRowSettledDaysAgo(string $connectionId, string $ref, int $daysAgo, array $attributes = []): PaymentSettlement
{
    return PaymentSettlement::factory()->create(array_merge([
        'connection_id' => $connectionId,
        'external_ref' => $ref,
        'status' => SettlementStatus::PendingPayout,
        'provider_settled_at' => now()->subDays($daysAgo),
    ], $attributes));
}

it('buckets pending money by days since the gateway settled it, identically in any operator timezone (S-22)', function (string $timezone) {
    // Freeze "now" at the same INSTANT expressed in the operator's zone —
    // the aging math must not care.
    Carbon::setTestNow(Carbon::parse('2026-07-28 09:30:00', 'UTC')->setTimezone($timezone));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-28 09:30:00', 'UTC')->setTimezone($timezone));

    $connection = SettlementTestFactory::stripeConnection();

    pendingRowSettledDaysAgo($connection->id, 'txn_age_2d', 2, ['net_minor' => 1_000, 'gross_minor' => 1_000, 'fee_minor' => 0, 'fee_tax_minor' => 0]);
    pendingRowSettledDaysAgo($connection->id, 'txn_age_10d', 10, ['net_minor' => 2_000, 'gross_minor' => 2_000, 'fee_minor' => 0, 'fee_tax_minor' => 0]);
    pendingRowSettledDaysAgo($connection->id, 'txn_age_40d', 40, ['net_minor' => 4_000, 'gross_minor' => 4_000, 'fee_minor' => 0, 'fee_tax_minor' => 0]);

    $report = agingService()->pendingPayoutAging($connection->id);

    expect($report)->toHaveCount(1);
    $entry = $report[0];

    expect($entry['connection_id'])->toBe($connection->id)
        ->and($entry['currency'])->toBe('JPY')
        ->and($entry['total_net_minor'])->toBe(7_000)
        ->and($entry['row_count'])->toBe(3)
        ->and($entry['oldest_age_days'])->toBe(40)
        // Default edges [3, 7, 14, 30] → buckets 0-3d, 4-7d, 8-14d, 15-30d, 31d+.
        ->and($entry['buckets']['0-3d'])->toBe(['net_minor' => 1_000, 'row_count' => 1])
        ->and($entry['buckets']['8-14d'])->toBe(['net_minor' => 2_000, 'row_count' => 1])
        ->and($entry['buckets']['31d+'])->toBe(['net_minor' => 4_000, 'row_count' => 1])
        ->and($entry['buckets']['4-7d'])->toBe(['net_minor' => 0, 'row_count' => 0]);
})->with(['UTC', 'Asia/Tokyo', 'Asia/Ho_Chi_Minh']);

it('flags a connection whose oldest pending row exceeds the per-provider threshold', function () {
    config()->set('payments.settlement.aging_alert_days.stripe', 7);

    $connection = SettlementTestFactory::stripeConnection();
    pendingRowSettledDaysAgo($connection->id, 'txn_over', 10);

    $report = agingService()->pendingPayoutAging($connection->id);

    expect($report[0]['over_threshold'])->toBeTrue();
});

it('does not flag a connection within its provider threshold — thresholds are per provider, not global (G4)', function () {
    // PayPay settles monthly: 10 days pending is NORMAL there.
    config()->set('payments.settlement.aging_alert_days.paypay', 45);

    $connection = SettlementTestFactory::stripeConnection();
    pendingRowSettledDaysAgo($connection->id, 'txn_pp_normal', 10, ['provider' => 'paypay']);

    $report = agingService()->pendingPayoutAging($connection->id);

    expect($report[0]['over_threshold'])->toBeFalse();
});

it('only counts pending_payout rows — reconciled, orphan and mismatch money is not "waiting for payout"', function () {
    $connection = SettlementTestFactory::stripeConnection();

    pendingRowSettledDaysAgo($connection->id, 'txn_pending_only', 2, ['net_minor' => 9_640, 'gross_minor' => 10_000, 'fee_minor' => 360, 'fee_tax_minor' => 0]);
    PaymentSettlement::factory()->reconciled()->create(['connection_id' => $connection->id, 'external_ref' => 'txn_done']);
    PaymentSettlement::factory()->orphan()->create(['connection_id' => $connection->id, 'external_ref' => 'txn_lost']);

    $report = agingService()->pendingPayoutAging($connection->id);

    expect($report)->toHaveCount(1)
        ->and($report[0]['row_count'])->toBe(1)
        ->and($report[0]['total_net_minor'])->toBe(9_640);
});

it('groups per connection and currency', function () {
    $connectionA = SettlementTestFactory::stripeConnection();
    $connectionB = SettlementTestFactory::stripeConnection();

    pendingRowSettledDaysAgo($connectionA->id, 'txn_conn_a', 1);
    pendingRowSettledDaysAgo($connectionB->id, 'txn_conn_b', 1);

    $all = agingService()->pendingPayoutAging();
    $onlyA = agingService()->pendingPayoutAging($connectionA->id);

    expect(count($all))->toBeGreaterThanOrEqual(2)
        ->and($onlyA)->toHaveCount(1)
        ->and($onlyA[0]['connection_id'])->toBe($connectionA->id);
});

it('sums signed values — pending refunds reduce the money still expected from the gateway (S-11)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    pendingRowSettledDaysAgo($connection->id, 'txn_sign_pay', 2, ['net_minor' => 9_640, 'gross_minor' => 10_000, 'fee_minor' => 360, 'fee_tax_minor' => 0]);
    pendingRowSettledDaysAgo($connection->id, 'txn_sign_ref', 2, ['kind' => 'refund', 'net_minor' => -2_000, 'gross_minor' => -2_000, 'fee_minor' => 0, 'fee_tax_minor' => 0]);

    $report = agingService()->pendingPayoutAging($connection->id);

    expect($report[0]['total_net_minor'])->toBe(7_640);
});
