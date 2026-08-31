<?php

use App\Models\GatewayPayout;
use App\Models\PaymentSettlement;
use App\Services\Payment\Settlement\Enums\GatewayPayoutStatus;
use App\Services\Payment\Settlement\Enums\SettlementKind;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\Stripe\StripeSettlementClient;
use App\Services\Payment\Settlement\Stripe\StripeSettlementRecorder;
use Tests\Fakes\Payment\FakeStripeSettlementClient;
use Tests\Support\Payment\SettlementTestFactory;

/**
 * Plan-050 M2/T2.3 — payout attach + Σ verification (TESTS.md: payout
 * reconcile). Σ match/mismatch (S-12) · payout failed → rows released
 * (S-10) · double-invoke idempotency (S-23) · negative payout (S-11) ·
 * unknown txn type → manual (S-13).
 */
function fakePayoutClient(): FakeStripeSettlementClient
{
    $fake = new FakeStripeSettlementClient;
    app()->instance(StripeSettlementClient::class, $fake);

    return $fake;
}

function payoutRecorder(): StripeSettlementRecorder
{
    return app(StripeSettlementRecorder::class);
}

it('attaches settlement rows to a paid payout and reconciles when Σ net equals the payout net (T2.3)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    $rowA = PaymentSettlement::factory()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_pay_a',
        'gross_minor' => 10_000, 'fee_minor' => 360, 'fee_tax_minor' => 0, 'net_minor' => 9_640,
    ]);
    $rowB = PaymentSettlement::factory()->refund()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_pay_b',
        'gross_minor' => -2_000, 'fee_minor' => 0, 'fee_tax_minor' => 0, 'net_minor' => -2_000,
    ]);

    fakePayoutClient()->withPayoutListing('po_001', [
        ['id' => 'txn_pay_a', 'type' => 'charge', 'amount' => 10_000, 'fee' => 360, 'net' => 9_640, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => []],
        ['id' => 'txn_pay_b', 'type' => 'refund', 'amount' => -2_000, 'fee' => 0, 'net' => -2_000, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => []],
        // The payout's own transfer transaction is skipped, not settled.
        ['id' => 'txn_po_self', 'type' => 'payout', 'amount' => -7_640, 'fee' => 0, 'net' => -7_640, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => []],
    ]);

    $event = SettlementTestFactory::stripeEvent($connection, 'payout.paid', [
        'payout_snapshot' => ['id' => 'po_001', 'amount' => 7_640, 'currency' => 'jpy', 'status' => 'paid', 'arrival_date' => 1785100000],
    ]);

    expect(payoutRecorder()->applyProviderEvent($event))->toBe('settlement_payout_reconciled');

    $payout = GatewayPayout::query()->where('external_payout_id', 'po_001')->firstOrFail();
    expect($payout->status)->toBe(GatewayPayoutStatus::Paid)
        ->and($payout->net_minor)->toBe(7_640)
        ->and($payout->reconciled_at)->not->toBeNull();

    expect($rowA->fresh()->payout_id)->toBe($payout->id)
        ->and($rowA->fresh()->status)->toBe(SettlementStatus::Reconciled)
        ->and($rowB->fresh()->status)->toBe(SettlementStatus::Reconciled);

    // No settlement row was created for the payout's own transfer txn.
    expect(PaymentSettlement::query()->where('external_ref', 'txn_po_self')->exists())->toBeFalse();
});

it('marks the payout mismatch and NEVER auto-balances when Σ net differs (S-12 [HARD])', function () {
    $connection = SettlementTestFactory::stripeConnection();

    PaymentSettlement::factory()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_mm_a',
        'gross_minor' => 10_000, 'fee_minor' => 360, 'fee_tax_minor' => 0, 'net_minor' => 9_640,
    ]);

    fakePayoutClient()->withPayoutListing('po_mm', [
        ['id' => 'txn_mm_a', 'type' => 'charge', 'amount' => 10_000, 'fee' => 360, 'net' => 9_640, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => []],
    ]);

    $event = SettlementTestFactory::stripeEvent($connection, 'payout.paid', [
        // Payout claims 10 000 but attached rows sum to 9 640.
        'payout_snapshot' => ['id' => 'po_mm', 'amount' => 10_000, 'currency' => 'jpy', 'status' => 'paid'],
    ]);

    $rowCountBefore = PaymentSettlement::query()->count();

    expect(payoutRecorder()->applyProviderEvent($event))->toBe('settlement_payout_mismatch');

    $payout = GatewayPayout::query()->where('external_payout_id', 'po_mm')->firstOrFail();
    expect($payout->status)->toBe(GatewayPayoutStatus::Mismatch)
        ->and($payout->reconciled_at)->toBeNull()
        ->and($payout->metadata['reconciliation_mismatch']['expected_net_minor'])->toBe(10_000)
        ->and($payout->metadata['reconciliation_mismatch']['attached_net_minor'])->toBe(9_640);

    // S-12: no synthetic balancing row appeared.
    expect(PaymentSettlement::query()->count())->toBe($rowCountBefore);
});

it('backfills an unknown transaction type as kind=manual with the raw type preserved, and the payout still reconciles (S-13)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    PaymentSettlement::factory()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_known',
        'gross_minor' => 10_000, 'fee_minor' => 360, 'fee_tax_minor' => 0, 'net_minor' => 9_640,
    ]);

    fakePayoutClient()->withPayoutListing('po_unknown', [
        ['id' => 'txn_known', 'type' => 'charge', 'amount' => 10_000, 'fee' => 360, 'net' => 9_640, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => []],
        ['id' => 'txn_weird', 'type' => 'anticipation_repayment', 'amount' => -1_000, 'fee' => 0, 'net' => -1_000, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => []],
    ]);

    $event = SettlementTestFactory::stripeEvent($connection, 'payout.paid', [
        'payout_snapshot' => ['id' => 'po_unknown', 'amount' => 8_640, 'currency' => 'jpy', 'status' => 'paid'],
    ]);

    expect(payoutRecorder()->applyProviderEvent($event))->toBe('settlement_payout_reconciled');

    $manual = PaymentSettlement::query()->where('external_ref', 'txn_weird')->firstOrFail();
    expect($manual->kind)->toBe(SettlementKind::Manual)
        ->and($manual->metadata['raw_type'])->toBe('anticipation_repayment')
        ->and($manual->payout_id)->not->toBeNull();
});

it('accepts a negative (debit) payout — refund-heavy periods are legal values, no abs() (S-11)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    PaymentSettlement::factory()->refund()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_neg_a',
        'gross_minor' => -5_000, 'fee_minor' => 0, 'fee_tax_minor' => 0, 'net_minor' => -5_000,
    ]);

    fakePayoutClient()->withPayoutListing('po_neg', [
        ['id' => 'txn_neg_a', 'type' => 'refund', 'amount' => -5_000, 'fee' => 0, 'net' => -5_000, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => []],
    ]);

    $event = SettlementTestFactory::stripeEvent($connection, 'payout.paid', [
        'payout_snapshot' => ['id' => 'po_neg', 'amount' => -5_000, 'currency' => 'jpy', 'status' => 'paid'],
    ]);

    expect(payoutRecorder()->applyProviderEvent($event))->toBe('settlement_payout_reconciled');
    expect(GatewayPayout::query()->where('external_payout_id', 'po_neg')->firstOrFail()->net_minor)->toBe(-5_000);
});

it('is idempotent — applying payout.paid twice changes no state (S-23)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    PaymentSettlement::factory()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_idem_po',
        'gross_minor' => 10_000, 'fee_minor' => 360, 'fee_tax_minor' => 0, 'net_minor' => 9_640,
    ]);

    fakePayoutClient()->withPayoutListing('po_idem', [
        ['id' => 'txn_idem_po', 'type' => 'charge', 'amount' => 10_000, 'fee' => 360, 'net' => 9_640, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => []],
    ]);

    $event = SettlementTestFactory::stripeEvent($connection, 'payout.paid', [
        'payout_snapshot' => ['id' => 'po_idem', 'amount' => 9_640, 'currency' => 'jpy', 'status' => 'paid'],
    ]);

    expect(payoutRecorder()->applyProviderEvent($event))->toBe('settlement_payout_reconciled');

    $payoutCount = GatewayPayout::query()->count();
    $rowSnapshot = PaymentSettlement::query()->orderBy('external_ref')
        ->get(['external_ref', 'status', 'payout_id', 'net_minor'])->toArray();

    expect(payoutRecorder()->applyProviderEvent($event))->toBe('settlement_payout_reconciled');

    expect(GatewayPayout::query()->count())->toBe($payoutCount)
        ->and(PaymentSettlement::query()->orderBy('external_ref')
            ->get(['external_ref', 'status', 'payout_id', 'net_minor'])->toArray())
        ->toBe($rowSnapshot);
});

it('releases settlement rows back to pending_payout when the payout fails — money never loses its trail (S-10)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    PaymentSettlement::factory()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_fail_a',
        'gross_minor' => 10_000, 'fee_minor' => 360, 'fee_tax_minor' => 0, 'net_minor' => 9_640,
    ]);

    $fake = fakePayoutClient()->withPayoutListing('po_fail', [
        ['id' => 'txn_fail_a', 'type' => 'charge', 'amount' => 10_000, 'fee' => 360, 'net' => 9_640, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => []],
    ]);

    // First: payout.paid reconciles the row (it becomes IMMUTABLE per S-24 —
    // the failure path must go through the sanctioned release).
    payoutRecorder()->applyProviderEvent(SettlementTestFactory::stripeEvent($connection, 'payout.paid', [
        'payout_snapshot' => ['id' => 'po_fail', 'amount' => 9_640, 'currency' => 'jpy', 'status' => 'paid'],
    ]));

    $row = PaymentSettlement::query()->where('external_ref', 'txn_fail_a')->firstOrFail();
    expect($row->status)->toBe(SettlementStatus::Reconciled);

    // Then: the bank rejects the transfer → payout.failed.
    $outcome = payoutRecorder()->applyProviderEvent(SettlementTestFactory::stripeEvent($connection, 'payout.failed', [
        'payout_snapshot' => ['id' => 'po_fail', 'amount' => 9_640, 'currency' => 'jpy', 'status' => 'failed'],
    ]));

    expect($outcome)->toBe('settlement_payout_failed_released:1');

    $payout = GatewayPayout::query()->where('external_payout_id', 'po_fail')->firstOrFail();
    $row = $row->fresh();

    expect($payout->status)->toBe(GatewayPayoutStatus::Failed)
        ->and($payout->reconciled_at)->toBeNull()
        ->and($row->status)->toBe(SettlementStatus::PendingPayout)
        ->and($row->payout_id)->toBeNull()
        // The audit trail survives the release.
        ->and($row->metadata['released_from_payout_ids'])->toBe(['po_fail']);
});

it('never steals a row already attached to a different payout', function () {
    $connection = SettlementTestFactory::stripeConnection();

    $otherPayout = GatewayPayout::factory()->paid()->create([
        'connection_id' => $connection->id,
        'external_payout_id' => 'po_other',
    ]);
    $row = PaymentSettlement::factory()->create([
        'connection_id' => $connection->id,
        'external_ref' => 'txn_claimed',
        'payout_id' => $otherPayout->id,
        'gross_minor' => 10_000, 'fee_minor' => 360, 'fee_tax_minor' => 0, 'net_minor' => 9_640,
    ]);

    fakePayoutClient()->withPayoutListing('po_thief', [
        ['id' => 'txn_claimed', 'type' => 'charge', 'amount' => 10_000, 'fee' => 360, 'net' => 9_640, 'currency' => 'jpy', 'created' => 1785000000, 'fee_details' => []],
    ]);

    $outcome = payoutRecorder()->applyProviderEvent(SettlementTestFactory::stripeEvent($connection, 'payout.paid', [
        'payout_snapshot' => ['id' => 'po_thief', 'amount' => 9_640, 'currency' => 'jpy', 'status' => 'paid'],
    ]));

    // Row stays with its original payout; the new payout cannot account for
    // the money it claims → mismatch, loudly.
    expect($row->fresh()->payout_id)->toBe($otherPayout->id)
        ->and($outcome)->toBe('settlement_payout_mismatch');
});
