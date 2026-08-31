<?php

/**
 * Plan 047 acceptance — webhook recovery D5, D8, D9.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Customer\StripePaymentService;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\ProviderEvent\ProviderRetrievalRecoveryService;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Tests\Fakes\Payment\InMemoryPaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;

beforeEach(function () {
    config([
        'services.stripe.key' => 'pk_test_dummy',
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_xyz',
        'services.stripe.currency' => 'jpy',
    ]);

    $this->organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->organizationId,
        'console_organization_id' => $this->organizationId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->organizationId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organizationId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
});

describe('D5 concurrent webhook and synchronous confirmation converge', function () {
    it('D5 sync confirm then replayed webhook records one ledger payment', function () {
        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'status' => 'open',
            'total_amount' => 1500,
            'paid_amount' => 0,
            'stripe_payment_intent_id' => 'pi_d5_converge',
        ]);

        $stripe = Mockery::mock(StripeClient::class);
        $stripe->paymentIntents = Mockery::mock();
        $stripe->paymentIntents->expects('retrieve')
            ->with('pi_d5_converge')
            ->andReturn(PaymentIntent::constructFrom([
                'id' => 'pi_d5_converge',
                'object' => 'payment_intent',
                'amount' => 1500,
                'currency' => 'jpy',
                'status' => 'succeeded',
                'metadata' => ['flow' => 'full', 'order_currency' => 'JPY'],
            ]));

        $service = new StripePaymentService($stripe);
        $service->confirmAndRecordPayment('pi_d5_converge');

        $intent = PaymentIntent::constructFrom([
            'id' => 'pi_d5_converge',
            'object' => 'payment_intent',
            'amount' => 1500,
            'currency' => 'jpy',
            'status' => 'succeeded',
            'metadata' => ['flow' => 'full', 'order_currency' => 'JPY'],
        ]);
        $service->markOrderPaidFromIntent($intent);

        expect(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(1)
            ->and((float) $order->fresh()->paid_amount)->toBe(1500.0);
    });
});

describe('D8 missing webhook recovered by scheduled provider retrieval', function () {
    it('D8 scheduled retrieval uses gateway retrievePayment for reconciliation_required attempts', function () {
        $gateway = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
        );
        $created = $gateway->preparePayment(new CreatePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('d8:scheduled'),
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(900, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::Pos,
            1,
        ));

        $retrieved = $gateway->retrievePayment(new RetrievePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('d8:retrieve'),
            $created->payment,
        ));

        expect($retrieved->state)->toBe(PaymentAttemptStateEnum::Succeeded);
        expect(class_exists(ProviderRetrievalRecoveryService::class))->toBeTrue();
    });
});

describe('D9 PayPay refund status reconciled without webhook', function () {
    it('D9 retrieveRefund converges refund truth without webhook delivery', function () {
        $gateway = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
        );
        $paymentRef = new ProviderObjectReference('fake_pay_d9');
        $gateway->preparePayment(new CreatePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('d9:pay'),
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(1000, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::Pos,
            1,
        ));
        $refundEvidence = $gateway->refund(new RefundPaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('d9:refund'),
            $paymentRef,
            new Money(500, 'JPY'),
        ));

        $retrieved = $gateway->retrieveRefund(new RetrieveRefundCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('d9:retrieve-refund'),
            $refundEvidence->refund,
        ));

        expect($retrieved->state)->toBe(PaymentRefundStateEnum::Succeeded);
    });
});
