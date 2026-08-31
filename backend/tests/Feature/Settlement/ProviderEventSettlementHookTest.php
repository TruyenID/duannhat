<?php

use App\Models\GatewayPayout;
use App\Models\PaymentSettlement;
use App\Services\Payment\ProviderEvent\ProviderEventApplicator;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\Stripe\StripeSettlementClient;
use Tests\Fakes\Payment\FakeStripeSettlementClient;
use Tests\Support\Payment\SettlementTestFactory;

/**
 * Plan-050 M2 — integration of the settlement recorder into the plan-048
 * inbox applicator: settlement evidence rides every money-bearing Stripe
 * event FAIL-OPEN (a settlement fault never poisons the ledger outcome),
 * while payout events are owned by the recorder end-to-end.
 */
beforeEach(function () {
    // The applicator graph pulls in StripePaymentService → StripePaymentGateway,
    // whose constructor requires a configured secret. Dummy only — the
    // FakeStripeSettlementClient guarantees no real HTTP happens.
    config()->set([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_dummy_secret_for_tests',
    ]);
});

function hookFakeClient(): FakeStripeSettlementClient
{
    $fake = new FakeStripeSettlementClient;
    app()->instance(StripeSettlementClient::class, $fake);

    return $fake;
}

it('records settlement evidence when the applicator processes payment_intent.succeeded', function () {
    $connection = SettlementTestFactory::stripeConnection();

    hookFakeClient()
        ->withCharge(['id' => 'ch_hook_001', 'balance_transaction' => 'txn_hook_001'])
        ->withBalanceTransaction([
            'id' => 'txn_hook_001', 'type' => 'charge', 'amount' => 10_000, 'fee' => 360, 'net' => 9_640,
            'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => [],
        ]);

    // No local order/attempt for this intent: the ledger path answers
    // ignored_no_order, and the settlement row still lands (as orphan, S-19).
    $event = SettlementTestFactory::stripeEvent($connection, 'payment_intent.succeeded', [
        'intent_snapshot' => [
            'id' => 'pi_hook_001',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 10_000,
            'currency' => 'jpy',
            'latest_charge' => 'ch_hook_001',
        ],
    ], 'pi_hook_001');

    $outcome = app(ProviderEventApplicator::class)->apply((string) $event->id);

    expect($outcome)->toBe('ignored_no_order');

    $row = PaymentSettlement::query()->where('external_ref', 'txn_hook_001')->firstOrFail();
    expect($row->status)->toBe(SettlementStatus::Orphan)
        ->and($row->net_minor)->toBe(9_640);
});

it('stays fail-open — a settlement recorder fault never changes the ledger outcome (G6 backstop model)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    // Fake has NO charge registered → the recorder throws internally.
    hookFakeClient();

    $event = SettlementTestFactory::stripeEvent($connection, 'payment_intent.succeeded', [
        'intent_snapshot' => [
            'id' => 'pi_hook_002',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 10_000,
            'currency' => 'jpy',
            'latest_charge' => 'ch_hook_missing',
        ],
    ], 'pi_hook_002');

    $outcome = app(ProviderEventApplicator::class)->apply((string) $event->id);

    // The money pipeline outcome is untouched; no settlement row landed.
    expect($outcome)->toBe('ignored_no_order')
        ->and(PaymentSettlement::query()->count())->toBe(0);
});

it('routes payout.paid through the recorder as the event owner', function () {
    $connection = SettlementTestFactory::stripeConnection();

    PaymentSettlement::factory()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_hook_po',
        'gross_minor' => 10_000, 'fee_minor' => 360, 'fee_tax_minor' => 0, 'net_minor' => 9_640,
    ]);

    hookFakeClient()->withPayoutListing('po_hook', [
        ['id' => 'txn_hook_po', 'type' => 'charge', 'amount' => 10_000, 'fee' => 360, 'net' => 9_640, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => []],
    ]);

    $event = SettlementTestFactory::stripeEvent($connection, 'payout.paid', [
        'payout_snapshot' => ['id' => 'po_hook', 'amount' => 9_640, 'currency' => 'jpy', 'status' => 'paid'],
    ]);

    $outcome = app(ProviderEventApplicator::class)->apply((string) $event->id);

    expect($outcome)->toBe('settlement_payout_reconciled')
        ->and(GatewayPayout::query()->where('external_payout_id', 'po_hook')->firstOrFail()->reconciled_at)->not->toBeNull();
});

it('ignores a payout event that carries no snapshot and no payout reference', function () {
    $connection = SettlementTestFactory::stripeConnection();
    hookFakeClient();

    $event = SettlementTestFactory::stripeEvent($connection, 'payout.paid', []);

    expect(app(ProviderEventApplicator::class)->apply((string) $event->id))
        ->toBe('settlement_skipped_no_payout_reference');
});
