<?php

use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentOptionRailEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\Gateway\Commands\CancelPaymentCommand;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Enums\CapabilityFact;
use App\Services\Payment\Gateway\Enums\CapabilityOperator;
use App\Services\Payment\Gateway\Enums\CapabilitySupport;
use App\Services\Payment\Gateway\Enums\CapabilityVerificationState;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Enums\PaymentWorkflow;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\ValueObjects\CapabilityCondition;
use App\Services\Payment\Gateway\ValueObjects\CapabilityEvidence;
use App\Services\Payment\Gateway\ValueObjects\CapabilityLimits;
use App\Services\Payment\Gateway\ValueObjects\CapabilityPredicate;
use App\Services\Payment\Gateway\ValueObjects\CapabilityRule;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\CapabilityVerification;
use App\Services\Payment\Gateway\ValueObjects\CurrencyCapability;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\GatewayNextAction;
use App\Services\Payment\Gateway\ValueObjects\GatewayRequestContext;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\OperationCapability;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Gateway\ValueObjects\RecoveryCapability;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;

const CONNECTION_ID = '0198f55e-b836-7ac3-b5a0-860505607e9d';
const OPERATION_ID = '0198f55e-c2c8-7d5a-ad46-5b6e62cd7702';
const ORDER_ID = '0198f55e-c679-7f79-a309-0ea478272012';
const OPTION_ID = '0198f55e-c9f8-7b04-b530-2137a44c0098';

function connectionData(): GatewayConnectionData
{
    return new GatewayConnectionData(
        CONNECTION_ID,
        PaymentGatewayProviderCodeEnum::Stripe,
        PaymentGatewayEnvironmentEnum::Test,
        'acct_display_01',
        3,
    );
}

function requestContext(): GatewayRequestContext
{
    return new GatewayRequestContext(OPERATION_ID, 'pay:order-1000:attempt-1', 'req/trace-1');
}

/** @param array<string, mixed> $overrides */
function capabilitySet(array $overrides = []): CapabilitySet
{
    $supported = new CapabilityRule(CapabilitySupport::Supported);
    $unsupported = new CapabilityRule(CapabilitySupport::Unsupported);
    $conditional = new CapabilityRule(CapabilitySupport::Conditional, new CapabilityCondition([
        new CapabilityPredicate(CapabilityFact::AttemptProviderState, CapabilityOperator::Equals, 'requires_capture'),
    ]));
    $values = array_replace([
        'workflows' => [PaymentWorkflow::Sale, PaymentWorkflow::AuthorizeCapture],
        'operations' => [
            new OperationCapability(GatewayCapability::Create, $supported),
            new OperationCapability(GatewayCapability::RetrievePayment, $supported),
            new OperationCapability(GatewayCapability::Authorize, $conditional),
            new OperationCapability(GatewayCapability::Capture, $conditional),
            new OperationCapability(GatewayCapability::Refund, $unsupported),
            new OperationCapability(GatewayCapability::WebhookVerification, $supported),
        ],
        'limits' => new CapabilityLimits($conditional, $unsupported, $unsupported, 50, 1_000_000, 604800),
        'recovery' => new RecoveryCapability(true, false, true, 'provider_balance_report'),
    ], $overrides);

    return new CapabilitySet(
        'stripe.pi.card.web.v1', 4, PaymentGatewayProviderCodeEnum::Stripe, 'payment_intents', '2026-06-30',
        PaymentOptionRailEnum::Card, 'card', ['account_configured'], [PaymentChannelEnum::Pos, PaymentChannelEnum::Kiosk],
        ['browser'], [new CurrencyCapability('jpy', 0), new CurrencyCapability('USD', 2)], PaymentGatewayEnvironmentEnum::Test,
        $values['workflows'], $values['operations'], $values['limits'], $values['recovery'], ['connected_account'],
        new DateTimeImmutable('2026-01-01T00:00:00+00:00'), new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        new CapabilityVerification(CapabilityVerificationState::Verified, [
            new CapabilityEvidence(
                'contract:stripe-test-2026', 'account:connected-test-01', new DateTimeImmutable('2026-07-01T00:00:00+00:00'),
                'test-run:artifact-047', 'identity:reviewer-revision-7', new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
            ),
        ]),
    );
}

it('builds immutable provider-neutral commands for every gateway operation', function () {
    $connection = connectionData();
    $request = requestContext();
    $payment = new ProviderObjectReference('remote-payment-1');
    $refund = new ProviderObjectReference('remote-refund-1');
    $money = new Money(1000, 'jpy');
    $metadata = new RedactedData(['order_code' => 'ORDER_1000']);

    $commands = [
        new CreatePaymentCommand($connection, $request, ORDER_ID, OPTION_ID, $money, PaymentAttemptOperationEnum::Sale, PaymentChannelEnum::Pos, 7, 'opaque-source', $metadata),
        new RetrievePaymentCommand($connection, $request, $payment),
        new CapturePaymentCommand($connection, $request, $payment, $money, $metadata),
        new CancelPaymentCommand($connection, $request, $payment, $metadata),
        new RefundPaymentCommand($connection, $request, $payment, $money, $metadata),
        new RetrieveRefundCommand($connection, $request, $refund),
        new VerifyWebhookCommand($connection, '{"id":"evt_1"}', ['Provider-Signature' => 'top-secret'], 'webhook:trace-1'),
    ];

    foreach ($commands as $command) {
        $reflection = new ReflectionClass($command);
        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }

    expect($commands[0]->paymentSourceReference())->toBe('opaque-source')
        ->and($money->currency)->toBe('JPY');

    expect(fn () => $commands[0]->policyRevision = 8)->toThrow(Error::class);
});

it('keeps raw webhook and payment source material out of serialization and debug output', function () {
    $create = new CreatePaymentCommand(
        connectionData(), requestContext(), ORDER_ID, OPTION_ID, new Money(1000, 'JPY'),
        PaymentAttemptOperationEnum::Sale, PaymentChannelEnum::CustomerWeb, 1, 'source-secret-123',
    );
    $webhook = new VerifyWebhookCommand(
        connectionData(), '{"private":"body-secret-456"}', ['Authorization' => 'signature-secret-789'], 'trace-1',
    );

    $createOutput = json_encode($create, JSON_THROW_ON_ERROR).print_r($create, true);
    $webhookOutput = json_encode($webhook, JSON_THROW_ON_ERROR).print_r($webhook, true);
    $exported = var_export($create, true).var_export($webhook, true);

    expect($createOutput)->not->toContain('source-secret-123')
        ->and($webhookOutput)->not->toContain('body-secret-456')
        ->and($webhookOutput)->not->toContain('signature-secret-789')
        ->and($exported)->not->toContain('source-secret-123')
        ->and($exported)->not->toContain('body-secret-456')
        ->and($exported)->not->toContain('signature-secret-789')
        ->and($webhookOutput)->toContain(hash('sha256', '{"private":"body-secret-456"}'))
        ->and($webhook->rawBody())->toBe('{"private":"body-secret-456"}')
        ->and($webhook->headers()['Authorization'])->toBe('signature-secret-789');

    expect(fn () => serialize($create))->toThrow(LogicException::class, 'cannot be serialized')
        ->and(fn () => serialize($webhook))->toThrow(LogicException::class, 'cannot be serialized');
});

it('validates money UUID request identity create invariants and redacted data', function (Closure $factory, string $message) {
    expect($factory)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'positive minor amount' => [fn () => new Money(0, 'JPY'), 'minorAmount'],
    'ISO currency' => [fn () => new Money(1, 'JP'), 'currency'],
    'connection UUID' => [fn () => new GatewayConnectionData('bad', PaymentGatewayProviderCodeEnum::Stripe, PaymentGatewayEnvironmentEnum::Test, 'acct', 1), 'UUID'],
    'stable request key' => [fn () => new GatewayRequestContext(OPERATION_ID, 'bad key', 'trace-1'), 'unsupported characters'],
    'create operation' => [fn () => new CreatePaymentCommand(connectionData(), requestContext(), ORDER_ID, OPTION_ID, new Money(1, 'JPY'), PaymentAttemptOperationEnum::Capture, PaymentChannelEnum::Pos, 1), 'sale or authorize'],
    'policy revision' => [fn () => new CreatePaymentCommand(connectionData(), requestContext(), ORDER_ID, OPTION_ID, new Money(1, 'JPY'), PaymentAttemptOperationEnum::Sale, PaymentChannelEnum::Pos, 0), 'policyRevision'],
    'non-allowlisted API key' => [fn () => new RedactedData(['api_key' => 'unsafe']), 'not allowlisted'],
    'non-allowlisted credential' => [fn () => new RedactedData(['credential' => 'unsafe']), 'not allowlisted'],
    'credential in allowlisted field' => [fn () => new RedactedData(['reason_code' => 'sk_live_EXPOSED']), 'safe diagnostic code'],
    'nested metadata' => [fn () => new RedactedData(['reason_code' => ['authorization_token' => 'unsafe']]), 'top-level strings'],
    'non scalar metadata' => [fn () => new RedactedData(['reason_code' => new RuntimeException]), 'top-level strings'],
    'infinite metadata' => [fn () => new RedactedData(['outcome' => INF]), 'top-level strings'],
    'NaN metadata' => [fn () => new RedactedData(['outcome' => NAN]), 'top-level strings'],
]);

it('expresses the complete versioned and fail-closed capability snapshot without provider SDK types', function () {
    $capabilities = capabilitySet();
    $withinWindow = new DateTimeImmutable('2026-07-22T00:00:00+00:00');

    expect($capabilities->supports(GatewayCapability::Create, $withinWindow))->toBeTrue()
        ->and($capabilities->supports(GatewayCapability::Capture, $withinWindow))->toBeFalse()
        ->and($capabilities->supports(GatewayCapability::Capture, $withinWindow, ['attempt_provider_state' => 'requires_capture']))->toBeTrue()
        ->and($capabilities->supports(GatewayCapability::Refund, $withinWindow))->toBeFalse()
        ->and($capabilities->supports(GatewayCapability::Cancel, $withinWindow))->toBeFalse()
        ->and($capabilities->supports(GatewayCapability::Create, new DateTimeImmutable('2027-01-01T00:00:00+00:00')))->toBeFalse()
        ->and($capabilities->supports(GatewayCapability::Create, new DateTimeImmutable('2026-06-30T23:59:59+00:00')))->toBeFalse()
        ->and($capabilities->currency('jpy')?->minorUnit)->toBe(0)
        ->and($capabilities->appliesAt(new DateTimeImmutable('2026-07-22T00:00:00+00:00')))->toBeTrue()
        ->and($capabilities->appliesAt(new DateTimeImmutable('2027-01-01T00:00:00+00:00')))->toBeFalse()
        ->and($capabilities->verification->hasApplicableEvidence(new DateTimeImmutable('2026-06-30T23:59:59+00:00')))->toBeFalse()
        ->and($capabilities->verification->hasApplicableEvidence(new DateTimeImmutable('2026-07-01T00:00:00+00:00')))->toBeTrue()
        ->and($capabilities->verification->hasApplicableEvidence(new DateTimeImmutable('2026-12-31T23:59:59+00:00')))->toBeTrue()
        ->and($capabilities->verification->hasApplicableEvidence(new DateTimeImmutable('2027-01-01T00:00:00+00:00')))->toBeFalse()
        ->and(json_encode($capabilities, JSON_THROW_ON_ERROR))->toContain('stripe.pi.card.web.v1')
        ->and(json_encode($capabilities, JSON_THROW_ON_ERROR))->toContain('test-run:artifact-047');
});

it('rejects incomplete ambiguous and unverified capability claims', function () {
    $supported = new CapabilityRule(CapabilitySupport::Supported);
    $unsupported = new CapabilityRule(CapabilitySupport::Unsupported);

    expect(fn () => new CapabilityRule(CapabilitySupport::Conditional))->toThrow(InvalidArgumentException::class, 'machine-evaluable')
        ->and(fn () => new CapabilityVerification(CapabilityVerificationState::Verified, []))->toThrow(InvalidArgumentException::class, 'evidence')
        ->and(fn () => capabilitySet([
            'workflows' => [PaymentWorkflow::Sale],
            'operations' => [
                new OperationCapability(GatewayCapability::Create, $supported),
                new OperationCapability(GatewayCapability::Capture, $supported),
                new OperationCapability(GatewayCapability::WebhookVerification, $supported),
            ],
            'limits' => new CapabilityLimits($unsupported, $unsupported, $unsupported),
        ]))->toThrow(InvalidArgumentException::class, 'authorize/capture workflow')
        ->and(fn () => capabilitySet([
            'operations' => [
                new OperationCapability(GatewayCapability::Create, $supported),
                new OperationCapability(GatewayCapability::Authorize, $supported),
                new OperationCapability(GatewayCapability::Capture, $supported),
                new OperationCapability(GatewayCapability::Refund, $supported),
                new OperationCapability(GatewayCapability::WebhookVerification, $supported),
            ],
            'limits' => new CapabilityLimits($unsupported, $supported, $supported),
            'recovery' => new RecoveryCapability(true, false, false, 'daily_reconciliation'),
        ]))->toThrow(InvalidArgumentException::class, 'polling and retrieve-payment')
        ->and(fn () => new CapabilityEvidence(
            'contract:sk_live_EXPOSED', null, new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            'test-run:artifact', 'identity:reviewer', new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
        ))->toThrow(InvalidArgumentException::class, 'secret-free');
});

it('provides type-specific client actions without exposing handoff material to debug exporters', function () {
    $actions = [
        GatewayNextAction::redirect('https://checkout.example.test/continue?session=client-secret'),
        GatewayNextAction::qrCode('provider-qr-payload-secret'),
        GatewayNextAction::providerSdk(['client_secret' => 'client-session-value', 'livemode' => false]),
        GatewayNextAction::displayInstructions('PRESENT_CARD'),
        GatewayNextAction::wait(15),
    ];

    expect(json_encode($actions[0]->toClientArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->toContain('https://checkout.example.test/continue')
        ->and(json_encode($actions[1]->toClientArray(), JSON_THROW_ON_ERROR))->toContain('provider-qr-payload-secret')
        ->and(json_encode($actions[0], JSON_THROW_ON_ERROR))->not->toContain('client-secret')
        ->and($actions[2]->payload()['client_secret'])->toBe('client-session-value')
        ->and(var_export($actions, true))->not->toContain('client-secret')
        ->and(var_export($actions, true))->not->toContain('provider-qr-payload-secret')
        ->and(var_export($actions, true))->not->toContain('client-session-value');

    expect(fn () => GatewayNextAction::redirect('https://.'))
        ->toThrow(InvalidArgumentException::class, 'HTTPS URL');
    expect(fn () => GatewayNextAction::redirect("https://example.test/\r\nnext"))
        ->toThrow(InvalidArgumentException::class, 'HTTPS URL');
});

it('retains normalized and raw states with only safe next actions and summaries', function () {
    $action = GatewayNextAction::redirect('https://checkout.example.test/continue');
    $payment = new GatewayPaymentResult(
        PaymentAttemptStateEnum::ActionRequired,
        'requires_action',
        new ProviderObjectReference('remote-payment-1'),
        null,
        $action,
        new RedactedData(['reason_code' => '3ds_required']),
    );
    $refund = new GatewayRefundResult(
        PaymentRefundStateEnum::Pending,
        'accepted',
        new ProviderObjectReference('remote-refund-1'),
        new Money(250, 'JPY'),
    );

    expect($payment->state)->toBe(PaymentAttemptStateEnum::ActionRequired)
        ->and($payment->rawStatus)->toBe('requires_action')
        ->and($payment->nextAction)->toBe($action)
        ->and($refund->state)->toBe(PaymentRefundStateEnum::Pending)
        ->and($refund->rawStatus)->toBe('accepted');

    expect(fn () => new GatewayPaymentResult(PaymentAttemptStateEnum::ActionRequired, 'requires_action'))
        ->toThrow(InvalidArgumentException::class, 'next action');
    expect(fn () => new GatewayPaymentResult(PaymentAttemptStateEnum::Succeeded, 'succeeded', nextAction: $action))
        ->toThrow(InvalidArgumentException::class, 'Only an action-required');
    expect(fn () => new GatewayPaymentResult(PaymentAttemptStateEnum::Prepared, 'prepared'))
        ->toThrow(InvalidArgumentException::class, 'local prepared');
    expect(fn () => new GatewayPaymentResult(PaymentAttemptStateEnum::Succeeded, 'succeeded'))
        ->toThrow(InvalidArgumentException::class, 'provider payment identity');
    expect(fn () => new GatewayPaymentResult(PaymentAttemptStateEnum::Succeeded, 'succeeded', new ProviderObjectReference('remote-payment-1')))
        ->toThrow(InvalidArgumentException::class, 'processed money');
    expect(fn () => new GatewayRefundResult(PaymentRefundStateEnum::Succeeded, 'succeeded'))
        ->toThrow(InvalidArgumentException::class, 'provider refund identity');
});

it('represents a verified event without retaining raw provider payload', function () {
    $event = new VerifiedGatewayEvent(
        'evt-safe-id',
        'payment.updated',
        new DateTimeImmutable('2026-07-22T10:00:00+00:00'),
        hash('sha256', 'raw-body'),
        new ProviderObjectReference('remote-payment-1'),
        payload: new RedactedData(['raw_status' => 'processing']),
    );

    $serialized = json_encode($event, JSON_THROW_ON_ERROR);

    expect($serialized)->toContain('payment.updated')
        ->and($serialized)->toContain('processing')
        ->and($serialized)->not->toContain('raw-body');
});

it('has no Eloquent model or provider SDK dependency in the gateway boundary', function () {
    $root = dirname(__DIR__, 4).'/app/Services/Payment/Gateway';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $php = '';

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $path = $file->getPathname();
            // Provider ADAPTER directories are the one place a provider SDK is
            // allowed — that is what an adapter is. The rule being enforced here
            // is that the provider-neutral boundary around them (Contracts,
            // ValueObjects, Results, Enums, the registry) stays SDK-free.
            // Gateway/PayPay landed after this test was written (Gate 8, T8.1),
            // so it needs the same exclusion Gateway/Stripe already had.
            if (str_contains($path, '/Gateway/Stripe/') || str_contains($path, '/Gateway/PayPay/')) {
                continue;
            }

            $php .= file_get_contents($path);
        }
    }

    expect($php)->not->toContain('Illuminate\\Database')
        ->and($php)->not->toContain('App\\Models')
        ->and($php)->not->toContain('App\\Omnify\\Modules')
        ->and($php)->not->toContain('Stripe\\')
        ->and($php)->not->toContain('PayPay')
        ->and($php)->not->toContain('SBPayment');
});
