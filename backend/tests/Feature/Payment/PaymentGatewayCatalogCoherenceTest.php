<?php

use App\Models\PaymentGatewayOption;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\Enums\PaymentWorkflow;
use App\Services\Payment\Policy\Persistence\PaymentGatewayCapabilityMapper;
use Database\Seeders\PaymentGatewayCatalogSeeder;

/**
 * #1158 — every release-managed catalog row must hydrate into a CapabilitySet.
 *
 * The seeded Stripe/PayPay rows declare the `authorize_capture` workflow without
 * a separate `authorize` operation, because both providers authorize THROUGH
 * create (PaymentIntents `capture_method: manual`, PayPay OPA preauth). Hydrating
 * them used to throw out of `CapabilitySet::assertCoherent`, which surfaced as a
 * 500 on `GET /api/v1/shops/{slug}/payment-configuration` for any branch holding
 * a stripe or paypay connection.
 */
it('hydrates every seeded catalog option into a coherent capability set', function () {
    $this->seed(PaymentGatewayCatalogSeeder::class);

    $mapper = app(PaymentGatewayCapabilityMapper::class);
    $options = PaymentGatewayOption::query()->with('provider')->get();

    expect($options)->not->toBeEmpty();

    foreach ($options as $option) {
        foreach (PaymentGatewayEnvironmentEnum::cases() as $environment) {
            $capability = $mapper->catalogFromOption($option, $option->provider, $environment);

            expect($capability->id)->toBe($option->code);
        }
    }
});

it('accepts an authorize/capture workflow that authorizes through create', function () {
    $this->seed(PaymentGatewayCatalogSeeder::class);

    $option = PaymentGatewayOption::query()
        ->with('provider')
        ->where('code', PaymentGatewayCatalogSeeder::STRIPE_OPTION_CODE)
        ->firstOrFail();

    $capability = app(PaymentGatewayCapabilityMapper::class)
        ->catalogFromOption($option, $option->provider, PaymentGatewayEnvironmentEnum::Test);

    expect($capability->workflows)->toContain(PaymentWorkflow::AuthorizeCapture)
        ->and($capability->operation(GatewayCapability::Authorize))->toBeNull()
        ->and($capability->operation(GatewayCapability::Create))->not->toBeNull()
        ->and($capability->operation(GatewayCapability::Capture))->not->toBeNull();
});
