<?php

use App\Models\PaymentAttempt;
use App\Models\PaymentSettlement;
use App\Services\Payment\Settlement\Enums\SettlementKind;
use App\Services\Payment\Settlement\Enums\SettlementSource;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\Stripe\StripeSettlementClient;
use App\Services\Payment\Settlement\Stripe\StripeSettlementRecorder;
use Tests\Fakes\Payment\FakeStripeSettlementClient;
use Tests\Support\Payment\SettlementTestFactory;

/**
 * Plan-050 M2 — Stripe balance-transaction ingest (TESTS.md: Stripe ingest).
 *
 * Kind mapping · idempotency · refund fee=0 per statement (S-07) ·
 * dispute pair + append-only reversal (S-08) · orphan-before-payment (S-19)
 * · currency mismatch (S-17).
 */
function fakeSettlementClient(): FakeStripeSettlementClient
{
    $fake = new FakeStripeSettlementClient;
    app()->instance(StripeSettlementClient::class, $fake);

    return $fake;
}

function settlementRecorder(): StripeSettlementRecorder
{
    return app(StripeSettlementRecorder::class);
}

function succeededStripeAttempt(string $connectionId, string $intentId, string $currency = 'JPY'): PaymentAttempt
{
    return PaymentAttempt::factory()->create([
        'connection_id' => $connectionId,
        'provider_object_id' => $intentId,
        'provider' => 'stripe',
        'state' => 'succeeded',
        'currency' => $currency,
        'amount_minor' => 10_000,
        'finalized_at' => now(),
    ]);
}

it('records a kind=payment row from the charge balance transaction — gross/fee/net exactly as Stripe reported (T2.1)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $attempt = succeededStripeAttempt($connection->id, 'pi_ingest_001');

    fakeSettlementClient()
        ->withCharge(['id' => 'ch_001', 'balance_transaction' => 'txn_001'])
        ->withBalanceTransaction([
            'id' => 'txn_001',
            'type' => 'charge',
            'amount' => 10_000,
            'fee' => 360,
            'net' => 9_640,
            'currency' => 'jpy',
            'created' => 1785000000,
            'fee_details' => [['type' => 'stripe_fee', 'amount' => 360, 'currency' => 'jpy']],
        ]);

    $event = SettlementTestFactory::stripeEvent($connection, 'payment_intent.succeeded', [
        'intent_snapshot' => ['id' => 'pi_ingest_001', 'latest_charge' => 'ch_001'],
    ], 'pi_ingest_001');

    $outcome = settlementRecorder()->applyProviderEvent($event);

    expect($outcome)->toBe('settlement_payment_recorded');

    $row = PaymentSettlement::query()->where('external_ref', 'txn_001')->firstOrFail();
    expect($row->kind)->toBe(SettlementKind::Payment)
        ->and($row->source)->toBe(SettlementSource::Api)
        ->and($row->status)->toBe(SettlementStatus::PendingPayout)
        ->and($row->gross_minor)->toBe(10_000)
        ->and($row->fee_minor)->toBe(360)
        ->and($row->fee_tax_minor)->toBe(0)
        ->and($row->net_minor)->toBe(9_640)
        ->and($row->currency)->toBe('JPY')
        ->and($row->payment_attempt_id)->toBe($attempt->id)
        ->and($row->provider_settled_at)->not->toBeNull();
});

it('is idempotent by (provider, external_ref) — a redelivered webhook never doubles a row (S-02)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    succeededStripeAttempt($connection->id, 'pi_ingest_002');

    fakeSettlementClient()
        ->withCharge(['id' => 'ch_002', 'balance_transaction' => 'txn_002'])
        ->withBalanceTransaction([
            'id' => 'txn_002', 'type' => 'charge', 'amount' => 5_000, 'fee' => 180, 'net' => 4_820,
            'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => [],
        ]);

    $event = SettlementTestFactory::stripeEvent($connection, 'payment_intent.succeeded', [
        'intent_snapshot' => ['id' => 'pi_ingest_002', 'latest_charge' => 'ch_002'],
    ], 'pi_ingest_002');

    expect(settlementRecorder()->applyProviderEvent($event))->toBe('settlement_payment_recorded')
        ->and(settlementRecorder()->applyProviderEvent($event))->toBe('settlement_payment_duplicate')
        ->and(PaymentSettlement::query()->where('external_ref', 'txn_002')->count())->toBe(1);
});

it('reads fee tax from fee_details of type tax when the statement carries it — never hardcoded (S-16)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    succeededStripeAttempt($connection->id, 'pi_ingest_003');

    fakeSettlementClient()
        ->withCharge(['id' => 'ch_003', 'balance_transaction' => 'txn_003'])
        ->withBalanceTransaction([
            'id' => 'txn_003', 'type' => 'charge', 'amount' => 10_000, 'fee' => 396, 'net' => 9_604,
            'currency' => 'jpy', 'created' => 1785000000,
            'fee_details' => [
                ['type' => 'stripe_fee', 'amount' => 360, 'currency' => 'jpy'],
                ['type' => 'tax', 'amount' => 36, 'currency' => 'jpy'],
            ],
        ]);

    settlementRecorder()->applyProviderEvent(SettlementTestFactory::stripeEvent($connection, 'payment_intent.succeeded', [
        'intent_snapshot' => ['id' => 'pi_ingest_003', 'latest_charge' => 'ch_003'],
    ]));

    $row = PaymentSettlement::query()->where('external_ref', 'txn_003')->firstOrFail();
    expect($row->fee_minor)->toBe(360)
        ->and($row->fee_tax_minor)->toBe(36)
        ->and($row->net_minor)->toBe(9_604)
        // Invariant survives the split: net = gross - fee - fee_tax.
        ->and($row->net_minor)->toBe($row->gross_minor - $row->fee_minor - $row->fee_tax_minor);
});

it('keeps a settlement whose payment has not arrived yet as orphan — offline replay re-matches later (S-19)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    // No attempt, no order payment for this intent.

    fakeSettlementClient()
        ->withCharge(['id' => 'ch_004', 'balance_transaction' => 'txn_004'])
        ->withBalanceTransaction([
            'id' => 'txn_004', 'type' => 'charge', 'amount' => 3_000, 'fee' => 108, 'net' => 2_892,
            'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => [],
        ]);

    settlementRecorder()->applyProviderEvent(SettlementTestFactory::stripeEvent($connection, 'payment_intent.succeeded', [
        'intent_snapshot' => ['id' => 'pi_orphan_004', 'latest_charge' => 'ch_004'],
    ]));

    $row = PaymentSettlement::query()->where('external_ref', 'txn_004')->firstOrFail();
    expect($row->status)->toBe(SettlementStatus::Orphan)
        ->and($row->payment_attempt_id)->toBeNull()
        ->and($row->metadata['provider_object_id'])->toBe('pi_orphan_004');
});

it('flags a currency disagreement as mismatch — never converts (S-17)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    succeededStripeAttempt($connection->id, 'pi_ingest_005', 'USD');

    fakeSettlementClient()
        ->withCharge(['id' => 'ch_005', 'balance_transaction' => 'txn_005'])
        ->withBalanceTransaction([
            'id' => 'txn_005', 'type' => 'charge', 'amount' => 10_000, 'fee' => 360, 'net' => 9_640,
            'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => [],
        ]);

    settlementRecorder()->applyProviderEvent(SettlementTestFactory::stripeEvent($connection, 'payment_intent.succeeded', [
        'intent_snapshot' => ['id' => 'pi_ingest_005', 'latest_charge' => 'ch_005'],
    ]));

    $row = PaymentSettlement::query()->where('external_ref', 'txn_005')->firstOrFail();
    expect($row->status)->toBe(SettlementStatus::Mismatch)
        ->and($row->currency)->toBe('JPY')
        ->and($row->metadata['currency_mismatch']['attempt_currency'])->toBe('USD');
});

it('records a refund row with the statement fee — Stripe keeps the original fee, refund txn fee is 0 (T2.2, S-07)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $attempt = succeededStripeAttempt($connection->id, 'pi_refund_001');

    fakeSettlementClient()->withBalanceTransaction([
        'id' => 'txn_refund_001', 'type' => 'refund', 'amount' => -10_000, 'fee' => 0, 'net' => -10_000,
        'currency' => 'jpy', 'created' => 1785100000, 'fee_details' => [],
    ]);

    $event = SettlementTestFactory::stripeEvent($connection, 'charge.refunded', [
        'charge_snapshot' => [
            'id' => 'ch_refund_001',
            'payment_intent' => 'pi_refund_001',
            'refunds' => ['data' => [
                ['id' => 're_001', 'balance_transaction' => 'txn_refund_001'],
            ]],
        ],
    ], 'pi_refund_001');

    expect(settlementRecorder()->applyProviderEvent($event))->toBe('settlement_refunds_recorded:1');

    $row = PaymentSettlement::query()->where('external_ref', 'txn_refund_001')->firstOrFail();
    expect($row->kind)->toBe(SettlementKind::Refund)
        ->and($row->gross_minor)->toBe(-10_000)
        ->and($row->fee_minor)->toBe(0)
        ->and($row->net_minor)->toBe(-10_000)
        ->and($row->payment_attempt_id)->toBe($attempt->id);
});

it('records each partial refund as its own row (S-09 — one row per money-event)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    succeededStripeAttempt($connection->id, 'pi_refund_002');

    fakeSettlementClient()
        ->withBalanceTransaction([
            'id' => 'txn_refund_p1', 'type' => 'refund', 'amount' => -3_000, 'fee' => 0, 'net' => -3_000,
            'currency' => 'jpy', 'created' => 1785100000, 'fee_details' => [],
        ])
        ->withBalanceTransaction([
            'id' => 'txn_refund_p2', 'type' => 'refund', 'amount' => -2_000, 'fee' => 0, 'net' => -2_000,
            'currency' => 'jpy', 'created' => 1785200000, 'fee_details' => [],
        ]);

    $event = SettlementTestFactory::stripeEvent($connection, 'charge.refunded', [
        'charge_snapshot' => [
            'id' => 'ch_refund_002',
            'payment_intent' => 'pi_refund_002',
            'refunds' => ['data' => [
                ['id' => 're_p1', 'balance_transaction' => 'txn_refund_p1'],
                ['id' => 're_p2', 'balance_transaction' => 'txn_refund_p2'],
            ]],
        ],
    ]);

    expect(settlementRecorder()->applyProviderEvent($event))->toBe('settlement_refunds_recorded:2')
        ->and(PaymentSettlement::query()->where('kind', SettlementKind::Refund->value)->count())->toBe(2);
});

it('splits a dispute withdrawal into dispute_withdrawal + dispute_fee rows whose nets sum to the txn net (T2.2, S-08)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    succeededStripeAttempt($connection->id, 'pi_dispute_001');

    fakeSettlementClient();

    $event = SettlementTestFactory::stripeEvent($connection, 'charge.dispute.created', [
        'dispute_snapshot' => [
            'id' => 'dp_001',
            'payment_intent' => 'pi_dispute_001',
            'amount' => 10_000,
            'currency' => 'jpy',
            'status' => 'needs_response',
            'balance_transactions' => [[
                'id' => 'txn_dispute_001',
                'type' => 'adjustment',
                'amount' => -10_000,
                'fee' => 1_500,
                'net' => -11_500,
                'currency' => 'jpy',
                'created' => 1785300000,
                'fee_details' => [['type' => 'stripe_fee', 'amount' => 1_500, 'currency' => 'jpy']],
            ]],
        ],
    ]);

    expect(settlementRecorder()->applyProviderEvent($event))->toBe('settlement_dispute_rows_recorded:2');

    $withdrawal = PaymentSettlement::query()->where('external_ref', 'txn_dispute_001')->firstOrFail();
    $fee = PaymentSettlement::query()->where('external_ref', 'txn_dispute_001:fee')->firstOrFail();

    expect($withdrawal->kind)->toBe(SettlementKind::DisputeWithdrawal)
        ->and($withdrawal->gross_minor)->toBe(-10_000)
        ->and($withdrawal->net_minor)->toBe(-10_000)
        ->and($fee->kind)->toBe(SettlementKind::DisputeFee)
        ->and($fee->gross_minor)->toBe(0)
        ->and($fee->fee_minor)->toBe(1_500)
        ->and($fee->net_minor)->toBe(-1_500)
        // The pair reconstructs the balance transaction's net exactly.
        ->and($withdrawal->net_minor + $fee->net_minor)->toBe(-11_500);
});

it('appends a dispute_reversal row on WON — withdrawal rows are never edited (S-08 append-only)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    succeededStripeAttempt($connection->id, 'pi_dispute_002');

    fakeSettlementClient();

    $withdrawalEvent = SettlementTestFactory::stripeEvent($connection, 'charge.dispute.funds_withdrawn', [
        'dispute_snapshot' => [
            'id' => 'dp_002', 'payment_intent' => 'pi_dispute_002', 'amount' => 10_000,
            'currency' => 'jpy', 'status' => 'under_review',
            'balance_transactions' => [[
                'id' => 'txn_dispute_002', 'type' => 'adjustment', 'amount' => -10_000, 'fee' => 1_500,
                'net' => -11_500, 'currency' => 'jpy', 'created' => 1785300000,
                'fee_details' => [['type' => 'stripe_fee', 'amount' => 1_500, 'currency' => 'jpy']],
            ]],
        ],
    ]);
    settlementRecorder()->applyProviderEvent($withdrawalEvent);

    $withdrawalBefore = PaymentSettlement::query()->where('external_ref', 'txn_dispute_002')->firstOrFail();

    $reversalEvent = SettlementTestFactory::stripeEvent($connection, 'charge.dispute.funds_reinstated', [
        'dispute_snapshot' => [
            'id' => 'dp_002', 'payment_intent' => 'pi_dispute_002', 'amount' => 10_000,
            'currency' => 'jpy', 'status' => 'won',
            'balance_transactions' => [
                [
                    'id' => 'txn_dispute_002', 'type' => 'adjustment', 'amount' => -10_000, 'fee' => 1_500,
                    'net' => -11_500, 'currency' => 'jpy', 'created' => 1785300000,
                    'fee_details' => [['type' => 'stripe_fee', 'amount' => 1_500, 'currency' => 'jpy']],
                ],
                [
                    'id' => 'txn_reinstate_002', 'type' => 'adjustment', 'amount' => 10_000, 'fee' => -1_500,
                    'net' => 11_500, 'currency' => 'jpy', 'created' => 1785400000,
                    'fee_details' => [['type' => 'stripe_fee', 'amount' => -1_500, 'currency' => 'jpy']],
                ],
            ],
        ],
    ]);
    expect(settlementRecorder()->applyProviderEvent($reversalEvent))->toBe('settlement_dispute_rows_recorded:1');

    $reversal = PaymentSettlement::query()->where('external_ref', 'txn_reinstate_002')->firstOrFail();
    expect($reversal->kind)->toBe(SettlementKind::DisputeReversal)
        ->and($reversal->gross_minor)->toBe(10_000)
        ->and($reversal->fee_minor)->toBe(-1_500)
        ->and($reversal->net_minor)->toBe(11_500);

    // Append-only: the withdrawal pair is byte-identical after the reversal.
    $withdrawalAfter = PaymentSettlement::query()->where('external_ref', 'txn_dispute_002')->firstOrFail();
    expect($withdrawalAfter->net_minor)->toBe($withdrawalBefore->net_minor)
        ->and($withdrawalAfter->kind)->toBe(SettlementKind::DisputeWithdrawal)
        ->and($withdrawalAfter->updated_at->timestamp)->toBe($withdrawalBefore->updated_at->timestamp)
        ->and(PaymentSettlement::query()->count())->toBe(3);
});

it('returns null for event types that carry no settlement meaning', function () {
    $connection = SettlementTestFactory::stripeConnection();
    fakeSettlementClient();

    $event = SettlementTestFactory::stripeEvent($connection, 'payment_intent.created', []);

    expect(settlementRecorder()->applyProviderEvent($event))->toBeNull()
        ->and(PaymentSettlement::query()->count())->toBe(0);
});
