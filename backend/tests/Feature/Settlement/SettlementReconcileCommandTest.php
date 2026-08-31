<?php

use App\Models\GatewayPayout;
use App\Models\PaymentAttempt;
use App\Models\PaymentSettlement;
use App\Services\Payment\Settlement\Enums\GatewayPayoutStatus;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use Tests\Support\Payment\SettlementTestFactory;

/**
 * Plan-050 M4 (T4.1) — settlements:reconcile command suite.
 *
 * Direction A (unsettled succeeded payments) · direction B (orphans +
 * mismatch payouts) · orphan re-match (S-05/S-19) · --dry-run ·
 * double-invoke idempotency (S-23) · per-provider windows.
 */
function reconcileSucceededAttempt(string $connectionId, string $intentId, int $daysAgo, string $provider = 'stripe'): PaymentAttempt
{
    return PaymentAttempt::factory()->create([
        'connection_id' => $connectionId,
        'provider_object_id' => $intentId,
        'provider' => $provider,
        'state' => 'succeeded',
        'currency' => 'JPY',
        'amount_minor' => 10_000,
        'finalized_at' => now()->subDays($daysAgo),
    ]);
}

it('reports a succeeded payment past the provider window with no settlement row (direction A)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $stale = reconcileSucceededAttempt($connection->id, 'pi_stale', 10);

    $this->artisan('settlements:reconcile')
        ->expectsOutputToContain($stale->id)
        ->assertExitCode(0);
});

it('does not report a payment that already has a settlement row (direction A)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $settled = reconcileSucceededAttempt($connection->id, 'pi_settled', 10);

    PaymentSettlement::factory()->create([
        'connection_id' => $connection->id,
        'payment_attempt_id' => $settled->id,
        'external_ref' => 'txn_settled_cmd',
    ]);

    $this->artisan('settlements:reconcile')
        ->doesntExpectOutputToContain($settled->id)
        ->assertExitCode(0);
});

it('does not report a payment still inside the provider window (direction A)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $fresh = reconcileSucceededAttempt($connection->id, 'pi_fresh', 2); // stripe window = 7d

    $this->artisan('settlements:reconcile')
        ->doesntExpectOutputToContain($fresh->id)
        ->assertExitCode(0);
});

it('ignores providers with no configured window — internal tenders have no gateway statement', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $internal = reconcileSucceededAttempt($connection->id, 'internal_ref_1', 30, 'internal');

    $this->artisan('settlements:reconcile')
        ->doesntExpectOutputToContain($internal->id)
        ->assertExitCode(0);
});

it('re-matches an orphan settlement to a late-arriving payment before alerting (S-05, S-19)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    $orphan = PaymentSettlement::factory()->orphan()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_orphan_rematch',
        'metadata' => ['provider_object_id' => 'pi_late_replay'],
    ]);

    // The offline replay landed AFTER the settlement (e.g. #1092 evidence path).
    $attempt = reconcileSucceededAttempt($connection->id, 'pi_late_replay', 0);

    $this->artisan('settlements:reconcile')->assertExitCode(0);

    $orphan = $orphan->fresh();
    expect($orphan->status)->toBe(SettlementStatus::PendingPayout)
        ->and($orphan->payment_attempt_id)->toBe($attempt->id)
        ->and($orphan->metadata['rematched_at'])->not->toBeNull();
});

it('previews but does not write the re-match under --dry-run', function () {
    $connection = SettlementTestFactory::stripeConnection();

    $orphan = PaymentSettlement::factory()->orphan()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_orphan_dry',
        'metadata' => ['provider_object_id' => 'pi_dry_replay'],
    ]);
    reconcileSucceededAttempt($connection->id, 'pi_dry_replay', 0);

    $this->artisan('settlements:reconcile', ['--dry-run' => true])->assertExitCode(0);

    expect($orphan->fresh()->status)->toBe(SettlementStatus::Orphan)
        ->and($orphan->fresh()->payment_attempt_id)->toBeNull();
});

it('keeps a truly unmatched orphan as orphan and reports it (direction B, S-05: kept forever)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    $orphan = PaymentSettlement::factory()->orphan()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_orphan_forever',
        'metadata' => ['provider_object_id' => 'pi_nobody_home'],
    ]);

    $this->artisan('settlements:reconcile')
        ->expectsOutputToContain('txn_orphan_forever')
        ->assertExitCode(0);

    expect($orphan->fresh()->status)->toBe(SettlementStatus::Orphan);
});

it('reports mismatch payouts (direction B, S-12)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    GatewayPayout::factory()->create([
        'connection_id' => $connection->id,
        'external_payout_id' => 'po_mismatch_cmd',
        'status' => GatewayPayoutStatus::Mismatch,
        'metadata' => ['reconciliation_mismatch' => ['expected_net_minor' => 100, 'attached_net_minor' => 90]],
    ]);

    $this->artisan('settlements:reconcile')
        ->expectsOutputToContain('po_mismatch_cmd')
        ->assertExitCode(0);
});

it('is idempotent — an immediate second invocation changes no state (S-23)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    // A bit of everything: unsettled payment, re-matchable orphan, forever
    // orphan, mismatch payout.
    reconcileSucceededAttempt($connection->id, 'pi_idem_stale', 10);
    PaymentSettlement::factory()->orphan()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_idem_rematch',
        'metadata' => ['provider_object_id' => 'pi_idem_replay'],
    ]);
    reconcileSucceededAttempt($connection->id, 'pi_idem_replay', 0);
    PaymentSettlement::factory()->orphan()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_idem_forever',
        'metadata' => ['provider_object_id' => 'pi_idem_nobody'],
    ]);
    GatewayPayout::factory()->create([
        'connection_id' => $connection->id,
        'external_payout_id' => 'po_idem_mm',
        'status' => GatewayPayoutStatus::Mismatch,
    ]);

    $this->artisan('settlements:reconcile')->assertExitCode(0);

    $settlementState = PaymentSettlement::query()->orderBy('external_ref')
        ->get(['external_ref', 'status', 'payment_attempt_id', 'payout_id'])->toArray();
    $payoutState = GatewayPayout::query()->orderBy('external_payout_id')
        ->get(['external_payout_id', 'status', 'reconciled_at'])->toArray();

    $this->artisan('settlements:reconcile')->assertExitCode(0);

    expect(PaymentSettlement::query()->orderBy('external_ref')
        ->get(['external_ref', 'status', 'payment_attempt_id', 'payout_id'])->toArray())
        ->toBe($settlementState)
        ->and(GatewayPayout::query()->orderBy('external_payout_id')
            ->get(['external_payout_id', 'status', 'reconciled_at'])->toArray())
        ->toBe($payoutState);
});

it('scopes to a single connection with --connection', function () {
    $connectionA = SettlementTestFactory::stripeConnection();
    $connectionB = SettlementTestFactory::stripeConnection();

    $inScope = reconcileSucceededAttempt($connectionA->id, 'pi_scope_a', 10);
    $outOfScope = reconcileSucceededAttempt($connectionB->id, 'pi_scope_b', 10);

    $this->artisan('settlements:reconcile', ['--connection' => $connectionA->id])
        ->expectsOutputToContain($inScope->id)
        ->doesntExpectOutputToContain($outOfScope->id)
        ->assertExitCode(0);
});
