<?php

use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnectionOption;
use App\Services\Payment\Settlement\SettlementFeeEstimator;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Payment\SettlementTestFactory;

/**
 * Plan-050 L1 — SettlementFeeEstimator unit suite + the G1
 * estimate-never-authoritative contract.
 */
it('computes percent + fixed fee estimates in minor units', function (int $amount, array $config, int $expected) {
    expect((new SettlementFeeEstimator)->estimate($amount, $config))->toBe($expected);
})->with([
    'card 3.6% of ¥10,000' => [10_000, ['percent' => 3.6, 'fixed_minor' => 0], 360],
    'percent rounds half-up' => [1_015, ['percent' => 3.6, 'fixed_minor' => 0], 37],
    'fixed only' => [10_000, ['fixed_minor' => 50], 50],
    'percent + fixed' => [10_000, ['percent' => 2.95, 'fixed_minor' => 10], 305],
    'zero-rate campaign' => [10_000, ['percent' => 0, 'fixed_minor' => 0], 0],
]);

it('returns null when no fee_estimate is declared — never invents a default rate (G1)', function () {
    $estimator = new SettlementFeeEstimator;

    expect($estimator->estimate(10_000, null))->toBeNull()
        ->and($estimator->estimate(10_000, ['note' => 'no numeric keys']))->toBeNull();
});

it('reads fee_estimate from the connection option merchant_configuration for an attempt', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $option = PaymentGatewayConnectionOption::factory()->create([
        'connection_id' => $connection->id,
        'merchant_configuration' => ['fee_estimate' => ['percent' => 3.6, 'fixed_minor' => 0]],
    ]);
    $attempt = PaymentAttempt::factory()->create([
        'connection_id' => $connection->id,
        'connection_option_id' => $option->id,
        'amount_minor' => 10_000,
        'currency' => 'JPY',
        'provider' => 'stripe',
        'state' => 'succeeded',
    ]);

    expect((new SettlementFeeEstimator)->estimateForAttempt($attempt))->toBe(360);
});

it('returns null for an attempt whose option declares no estimate', function () {
    $connection = SettlementTestFactory::stripeConnection();
    $option = PaymentGatewayConnectionOption::factory()->create([
        'connection_id' => $connection->id,
        'merchant_configuration' => [],
    ]);
    $attempt = PaymentAttempt::factory()->create([
        'connection_id' => $connection->id,
        'connection_option_id' => $option->id,
        'amount_minor' => 10_000,
        'provider' => 'stripe',
        'state' => 'succeeded',
    ]);

    expect((new SettlementFeeEstimator)->estimateForAttempt($attempt))->toBeNull();
});

/*
 * G1 CONTRACT — estimate is never authoritative.
 *
 * The settlement layer (rows, payouts, reconcile, aging — everything an
 * official report reads) must never touch payment_attempts.estimated_fee_minor.
 * Enforced the same way BusinessTimeArchitectureTest enforces #1091: by
 * scanning the source. If this fails, someone routed the dashboard estimate
 * into a booked number — that is exactly the G1 failure mode.
 */
it('never references estimated_fee_minor anywhere in the settlement services or models (G1 contract)', function () {
    $sources = [
        app_path('Services/Payment/Settlement/SettlementRowAssembler.php'),
        app_path('Services/Payment/Settlement/SettlementReconciliationService.php'),
        app_path('Services/Payment/Settlement/SettlementAgingReportService.php'),
        app_path('Services/Payment/Settlement/Stripe/StripeSettlementRecorder.php'),
        app_path('Services/Payment/Settlement/Stripe/StripeSettlementApiClient.php'),
        app_path('Models/PaymentSettlement.php'),
        app_path('Models/GatewayPayout.php'),
        app_path('Models/SettlementReportBatch.php'),
        app_path('Console/Commands/ReconcileSettlements.php'),
    ];

    foreach ($sources as $path) {
        expect(file_exists($path))->toBeTrue("Expected settlement source {$path} to exist.");
        expect(str_contains((string) file_get_contents($path), 'estimated_fee_minor'))
            ->toBeFalse("{$path} references estimated_fee_minor — estimates must never leak into settlement truth (G1).");
    }
});

it('keeps the estimate column nullable and out of payment_settlements entirely (G1 contract)', function () {
    expect(Schema::hasColumn('payment_attempts', 'estimated_fee_minor'))->toBeTrue()
        ->and(Schema::hasColumn('payment_settlements', 'estimated_fee_minor'))->toBeFalse();
});
