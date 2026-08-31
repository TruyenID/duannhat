<?php

use App\Services\Payment\Orchestration\Contracts\PaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;
use App\Services\Payment\Orchestration\Internal\EloquentPaymentAuthorityVerificationPort;
use App\Services\Payment\Orchestration\PaymentOrchestrator;
use App\Services\Payment\Orchestration\Support\PaymentOrchestrationLogContext;
use Illuminate\Support\Facades\Artisan;

it('binds the public payment mutation facade to PaymentOrchestrator', function () {
    expect(app(PaymentMutationFacade::class))->toBeInstanceOf(PaymentOrchestrator::class);
});

it('binds payment authority verification to the eloquent adapter', function () {
    expect(app(PaymentAuthorityVerificationPort::class))
        ->toBeInstanceOf(EloquentPaymentAuthorityVerificationPort::class);
});

it('redacts sensitive payment log fields', function () {
    $redacted = PaymentOrchestrationLogContext::redact([
        'correlation_id' => 'corr-1',
        'api_key' => 'sk_live_secretvalue',
        'nested' => [
            'card_number' => '4111111111111111',
            'note' => 'ok',
        ],
    ]);

    expect($redacted['correlation_id'])->toBe('corr-1')
        ->and($redacted['api_key'])->toBe('[REDACTED]')
        ->and($redacted['nested']['card_number'])->toBe('[REDACTED]')
        ->and($redacted['nested']['note'])->toBe('ok');
});

it('registers scheduled reconciliation artisan commands', function () {
    expect(Artisan::all())->toHaveKeys([
        'payments:reconcile-attempts',
        'payments:reconcile-refunds',
    ]);
});

it('configures concurrent refund reservation limit', function () {
    expect(config('payments.max_concurrent_refunds_per_payment'))->toBeInt()->toBeGreaterThan(0);
});

it('uses the payment_orchestration log channel', function () {
    expect(config('logging.channels.payment_orchestration'))->toBeArray()
        ->and(config('logging.channels.payment_orchestration.driver'))->toBe('daily');
});
