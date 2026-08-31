<?php

use App\Models\GatewayPayout;
use App\Models\PaymentSettlement;
use App\Models\SettlementReportBatch;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Support\Payment\SettlementTestFactory;

/**
 * Plan-050 — S-24 settled-guard (mirror of the PaymentPolicyRevision
 * immutability pattern) + import idempotency at the DB-CONSTRAINT level
 * (G3: idempotency lives in the constraint, not just app code).
 */
it('refuses to update a reconciled settlement row (S-24 [HARD])', function () {
    $row = PaymentSettlement::factory()->reconciled()->create();

    $row->update(['fee_minor' => 0]);
})->throws(LogicException::class, 'immutable');

it('refuses to delete a reconciled settlement row (S-24 [HARD])', function () {
    $row = PaymentSettlement::factory()->reconciled()->create();

    $row->delete();
})->throws(LogicException::class, 'append-only');

it('still allows editing rows that are not reconciled', function () {
    $row = PaymentSettlement::factory()->create();

    $row->update(['status' => SettlementStatus::Orphan]);

    expect($row->fresh()->status)->toBe(SettlementStatus::Orphan);
});

it('allows the sanctioned S-10 release path — and ONLY inside the explicit bypass', function () {
    $row = PaymentSettlement::factory()->reconciled()->create();

    PaymentSettlement::whileReleasingReconciled(function () use ($row): void {
        $row->update(['status' => SettlementStatus::PendingPayout, 'payout_id' => null]);
    });

    expect($row->fresh()->status)->toBe(SettlementStatus::PendingPayout);
});

it('closes the bypass again after the callback — a later stray edit still throws', function () {
    $released = PaymentSettlement::factory()->reconciled()->create();
    PaymentSettlement::whileReleasingReconciled(function () use ($released): void {
        $released->update(['status' => SettlementStatus::PendingPayout]);
    });

    $another = PaymentSettlement::factory()->reconciled()->create();

    expect(fn () => $another->update(['fee_minor' => 1]))
        ->toThrow(LogicException::class);
});

it('closes the bypass even when the callback throws', function () {
    try {
        PaymentSettlement::whileReleasingReconciled(function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    $row = PaymentSettlement::factory()->reconciled()->create();

    expect(fn () => $row->update(['fee_minor' => 1]))
        ->toThrow(LogicException::class);
});

it('enforces UNIQUE (provider, external_ref) at the database level (S-02, G3)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    PaymentSettlement::factory()->create([
        'connection_id' => $connection->id,
        'provider' => 'stripe',
        'external_ref' => 'txn_dup',
    ]);

    PaymentSettlement::factory()->create([
        'connection_id' => $connection->id,
        'provider' => 'stripe',
        'external_ref' => 'txn_dup',
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows the same external_ref under a DIFFERENT provider — uniqueness is per provider (S-21)', function () {
    PaymentSettlement::factory()->create(['provider' => 'stripe', 'external_ref' => 'ref_shared']);
    PaymentSettlement::factory()->create(['provider' => 'paypay', 'external_ref' => 'ref_shared']);

    expect(PaymentSettlement::query()->where('external_ref', 'ref_shared')->count())->toBe(2);
});

it('enforces UNIQUE (provider, external_payout_id) on gateway payouts', function () {
    GatewayPayout::factory()->create(['provider' => 'stripe', 'external_payout_id' => 'po_dup']);
    GatewayPayout::factory()->create(['provider' => 'stripe', 'external_payout_id' => 'po_dup']);
})->throws(UniqueConstraintViolationException::class);

it('enforces UNIQUE file_hash on report batches — re-importing the same file can never double (S-01)', function () {
    $hash = hash('sha256', 'the-very-same-report-file');

    SettlementReportBatch::factory()->create(['file_hash' => $hash]);
    SettlementReportBatch::factory()->create(['file_hash' => $hash]);
})->throws(UniqueConstraintViolationException::class);
