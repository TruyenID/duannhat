<?php

use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnectionOption;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Orchestration\Commands\FinalizePaymentCommand;
use App\Services\Payment\Orchestration\Internal\EloquentPaymentPersistence;
use Illuminate\Support\Str;
use Tests\Support\Payment\SettlementTestFactory;

/**
 * Plan-050 L1 (T1.2) — estimated_fee_minor stamping at the succeeded
 * transition through the orchestration persistence boundary, plus the
 * estimate-vs-truth separation.
 */
function stampAttempt(array $overrides = []): PaymentAttempt
{
    $connection = SettlementTestFactory::stripeConnection();
    $option = PaymentGatewayConnectionOption::factory()->create([
        'connection_id' => $connection->id,
        'merchant_configuration' => ['fee_estimate' => ['percent' => 3.6, 'fixed_minor' => 0]],
    ]);

    return PaymentAttempt::factory()->create(array_merge([
        'connection_id' => $connection->id,
        'connection_option_id' => $option->id,
        'provider' => 'stripe',
        'state' => 'processing',
        'currency' => 'JPY',
        'amount_minor' => 10_000,
        'estimated_fee_minor' => null,
        'version' => 1,
    ], $overrides));
}

function finalizeStampAttempt(PaymentAttempt $attempt, PaymentAttemptStateEnum $state): void
{
    $context = new MutationContext(
        (string) $attempt->organization_id,
        null,
        'test:'.Str::uuid(),
        (string) Str::uuid(),
        (int) $attempt->version,
    );

    $evidence = $state === PaymentAttemptStateEnum::Succeeded
        ? new GatewayPaymentResult(
            $state,
            'succeeded',
            new ProviderObjectReference('pi_'.Str::lower(Str::random(16))),
            new Money((int) $attempt->amount_minor, 'JPY'),
        )
        : new GatewayPaymentResult($state, 'failed');

    app(EloquentPaymentPersistence::class)->finalizeAttempt(
        new FinalizePaymentCommand($context, (string) $attempt->id, $evidence),
    );
}

it('stamps estimated_fee_minor from the connection option fee_estimate when the attempt succeeds', function () {
    $attempt = stampAttempt();

    finalizeStampAttempt($attempt, PaymentAttemptStateEnum::Succeeded);

    expect($attempt->fresh()->estimated_fee_minor)->toBe(360); // 3.6% of ¥10,000
});

it('leaves the estimate null when the option declares no fee_estimate — never a guessed default (G1)', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $option = PaymentGatewayConnectionOption::factory()->create([
        'connection_id' => $connection->id,
        'merchant_configuration' => [],
    ]);
    $attempt = stampAttempt([
        'connection_id' => $connection->id,
        'connection_option_id' => $option->id,
    ]);

    finalizeStampAttempt($attempt, PaymentAttemptStateEnum::Succeeded);

    expect($attempt->fresh()->estimated_fee_minor)->toBeNull();
});

it('does not stamp an estimate on a failed attempt', function () {
    $attempt = stampAttempt();

    finalizeStampAttempt($attempt, PaymentAttemptStateEnum::Failed);

    expect($attempt->fresh()->estimated_fee_minor)->toBeNull();
});

it('keeps an existing stamp on redelivery — the estimate reflects the catalog at payment time', function () {
    $attempt = stampAttempt();
    finalizeStampAttempt($attempt, PaymentAttemptStateEnum::Succeeded);
    expect($attempt->fresh()->estimated_fee_minor)->toBe(360);

    // The contract rate changes AFTER the sale…
    PaymentGatewayConnectionOption::query()
        ->where('id', $attempt->connection_option_id)
        ->update(['merchant_configuration' => json_encode(['fee_estimate' => ['percent' => 9.9, 'fixed_minor' => 0]])]);

    // …a webhook redelivery re-finalizes the attempt: the original stamp stays.
    finalizeStampAttempt($attempt->fresh(), PaymentAttemptStateEnum::Succeeded);

    expect($attempt->fresh()->estimated_fee_minor)->toBe(360);
});
