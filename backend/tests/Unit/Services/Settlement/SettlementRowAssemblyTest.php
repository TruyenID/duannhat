<?php

use App\Models\PaymentSettlement;
use App\Services\Payment\Settlement\Enums\SettlementKind;
use App\Services\Payment\Settlement\Enums\SettlementSource;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\Exceptions\SettlementRowIntegrityException;
use App\Services\Payment\Settlement\SettlementRowAssembler;
use Tests\Support\Payment\SettlementTestFactory;

/**
 * Plan-050 — SettlementRowAssembler unit suite (TESTS.md: row assembly).
 *
 * S-15 net assert · S-11 signed amounts · S-16 fee_tax from statement.
 */
function settlementRowAssembler(): SettlementRowAssembler
{
    return new SettlementRowAssembler;
}

it('assembles a JP card payment row — fee_tax 0 comes from the statement, not a hardcode (S-16)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    $attributes = settlementRowAssembler()->assemble(
        $connection,
        SettlementKind::Payment,
        SettlementSource::Api,
        'txn_jp_card_001',
        10_000,
        360,
        0,
        9_640,
        'jpy',
        now(),
    );

    expect($attributes['provider'])->toBe('stripe')
        ->and($attributes['kind'])->toBe(SettlementKind::Payment)
        ->and($attributes['gross_minor'])->toBe(10_000)
        ->and($attributes['fee_minor'])->toBe(360)
        ->and($attributes['fee_tax_minor'])->toBe(0)
        ->and($attributes['net_minor'])->toBe(9_640)
        ->and($attributes['currency'])->toBe('JPY')
        ->and($attributes['status'])->toBe(SettlementStatus::PendingPayout);
});

it('assembles a taxed-fee row — PayPay-style 10% JCT on the fee (S-16)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    // Fee 200 + fee tax 20 (10%): net = 10 000 - 200 - 20.
    $attributes = settlementRowAssembler()->assemble(
        $connection,
        SettlementKind::Payment,
        SettlementSource::Report,
        'row_paypay_001',
        10_000,
        200,
        20,
        9_780,
        'JPY',
        now(),
    );

    expect($attributes['fee_minor'])->toBe(200)
        ->and($attributes['fee_tax_minor'])->toBe(20)
        ->and($attributes['net_minor'])->toBe(9_780);
});

it('passes signed negative amounts through untouched — refunds and dispute withdrawals are legal values (S-11)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    $attributes = settlementRowAssembler()->assemble(
        $connection,
        SettlementKind::Refund,
        SettlementSource::Api,
        'txn_refund_001',
        -10_000,
        0,
        0,
        -10_000,
        'JPY',
        now(),
    );

    expect($attributes['gross_minor'])->toBe(-10_000)
        ->and($attributes['net_minor'])->toBe(-10_000);
});

it('refuses a row whose net contradicts gross - fee - fee_tax (S-15 [HARD])', function () {
    $connection = SettlementTestFactory::stripeConnection();

    settlementRowAssembler()->assemble(
        $connection,
        SettlementKind::Payment,
        SettlementSource::Api,
        'txn_corrupt_001',
        10_000,
        360,
        0,
        9_999, // ≠ 9 640
        'JPY',
        now(),
    );
})->throws(SettlementRowIntegrityException::class, 'net = gross - fee - fee_tax');

it('refuses a negative-fee reversal row that still breaks the invariant (S-15 holds for signed values too)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    // Reinstatement shape: gross 10 000, fee -1 500 (refunded) → net must be 11 500.
    settlementRowAssembler()->assemble(
        $connection,
        SettlementKind::DisputeReversal,
        SettlementSource::Api,
        'txn_reversal_bad',
        10_000,
        -1_500,
        0,
        10_000,
        'JPY',
        now(),
    );
})->throws(SettlementRowIntegrityException::class);

it('accepts a valid dispute reversal with a refunded (negative) fee', function () {
    $connection = SettlementTestFactory::stripeConnection();

    $attributes = settlementRowAssembler()->assemble(
        $connection,
        SettlementKind::DisputeReversal,
        SettlementSource::Api,
        'txn_reversal_ok',
        10_000,
        -1_500,
        0,
        11_500,
        'JPY',
        now(),
    );

    expect($attributes['fee_minor'])->toBe(-1_500)
        ->and($attributes['net_minor'])->toBe(11_500);
});

it('refuses a malformed currency (S-17: never guess, never convert)', function () {
    $connection = SettlementTestFactory::stripeConnection();

    settlementRowAssembler()->assemble(
        $connection,
        SettlementKind::Payment,
        SettlementSource::Api,
        'txn_bad_currency',
        100,
        0,
        0,
        100,
        'YENS',
        now(),
    );
})->throws(SettlementRowIntegrityException::class, 'currency');

it('refuses an empty external_ref — idempotency has nothing to key on without it', function () {
    $connection = SettlementTestFactory::stripeConnection();

    settlementRowAssembler()->assemble(
        $connection,
        SettlementKind::Payment,
        SettlementSource::Api,
        '',
        100,
        0,
        0,
        100,
        'JPY',
        now(),
    );
})->throws(SettlementRowIntegrityException::class, 'external_ref');

it('persistIdempotent resolves a duplicate (provider, external_ref) to the existing row instead of doubling (S-02, G3)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $assembler = settlementRowAssembler();

    $attributes = $assembler->assemble(
        $connection,
        SettlementKind::Payment,
        SettlementSource::Api,
        'txn_idem_001',
        5_000,
        180,
        0,
        4_820,
        'JPY',
        now(),
    );

    [$first, $createdFirst] = $assembler->persistIdempotent($attributes);
    [$second, $createdSecond] = $assembler->persistIdempotent($attributes);

    expect($createdFirst)->toBeTrue()
        ->and($createdSecond)->toBeFalse()
        ->and($second->id)->toBe($first->id)
        ->and(PaymentSettlement::query()->where('external_ref', 'txn_idem_001')->count())->toBe(1);
});
