<?php

/**
 * #1088 — Stripe Terminal runtime (server-driven card_present).
 *
 * The catalog capability `stripe.terminal.card_present.v1` used to be
 * catalog-only; this pins the RUNTIME that now backs it, kept fail-closed
 * behind payments.stripe_terminal.enabled until certification:
 *
 *   - flag OFF (default) → every endpoint 409 STRIPE_TERMINAL_DISABLED
 *   - reader registry: register-by-code → Location ensured per branch
 *     (metadata.tempo_branch_id), PeripheralDevice row persisted
 *     (payment_terminal + metadata.provider=stripe_terminal); re-register
 *     updates, never duplicates
 *   - charge: card_present intent (Terminal is the ONE legitimate
 *     payment_method_types use) → processPaymentIntent on the reader →
 *     awaiting-async PENDING ledger row; the payment_intent.succeeded
 *     webhook FLIPS it and settles the order (#1125 lifecycle, proven here
 *     end-to-end with a real signed webhook)
 *   - reader refusal → intent canceled, no pending row, clean 422
 *   - cancel: reader action + intent aborted → row failed; an intent that
 *     already succeeded is NEVER failed locally (webhook settles it)
 *
 * Real HTTP routes + real signed webhooks; only \Stripe\StripeClient mocked.
 */

require_once __DIR__.'/../Verify/Stripe/vst_helpers.php';

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\PeripheralDevice;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\Payment\Gateway\Stripe\StripePaymentGateway;
use App\Services\Payment\Terminal\StripeTerminalService;
use Mockery\MockInterface;
use Stripe\Collection;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Terminal\Location;
use Stripe\Terminal\Reader;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

uses()->group('payment');

beforeEach(function () {
    vstConfigureStripe(currency: 'jpy');
    config(['payments.stripe_terminal.enabled' => true]);

    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();
    $this->device = $this->fixtures->seedDevice('pos');
});

function terminalReaderObject(string $id = 'tmr_sim1', string $status = 'online', ?string $actionType = null): Reader
{
    return Reader::constructFrom([
        'id' => $id,
        'object' => 'terminal.reader',
        'device_type' => 'simulated_wisepos_e',
        'serial_number' => 'SIM-001',
        'status' => $status,
        'action' => $actionType === null ? null : ['type' => $actionType, 'status' => 'in_progress'],
    ]);
}

/** Bind BOTH the gateway (terminal ops) and the legacy webhook service to one mocked client. */
function terminalBindStripe(StripeClient $client): void
{
    app()->instance(StripePaymentGateway::class, new StripePaymentGateway($client));
    vstBindStripe($client);
}

function terminalMockClient(): StripeClient
{
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->terminal = Mockery::mock();
    $client->terminal->locations = Mockery::mock();
    $client->terminal->readers = Mockery::mock();
    $client->paymentIntents = Mockery::mock();

    return $client;
}

function terminalSeedReaderRow(object $ctx, string $readerId = 'tmr_sim1'): PeripheralDevice
{
    return PeripheralDevice::query()->create([
        'name' => 'Counter reader',
        'type' => 'payment_terminal',
        'is_active' => true,
        'metadata' => [
            'provider' => StripeTerminalService::PROVIDER,
            'stripe_reader_id' => $readerId,
            'stripe_location_id' => 'tml_1',
            'device_type' => 'simulated_wisepos_e',
        ],
        'organization_id' => $ctx->fixtures->organization->id,
        'branch_id' => $ctx->fixtures->shop->id,
    ]);
}

function terminalOrder(object $ctx, float $total = 3000.0): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $ctx->fixtures->organization->id,
        'brand_id' => $ctx->fixtures->brand->id,
        'branch_id' => $ctx->fixtures->shop->id,
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => $total,
        'paid_amount' => 0,
        'stripe_payment_intent_id' => null,
    ]);
}

function terminalPosHeaders(object $ctx): array
{
    return [
        'Authorization' => 'Bearer '.$ctx->device->device_token,
        'X-Shop-Slug' => $ctx->fixtures->shop->slug,
    ];
}

// =============================================================================
// 1. Fail-closed posture
// =============================================================================

it('fails closed with STRIPE_TERMINAL_DISABLED when the flag is off', function () {
    config(['payments.stripe_terminal.enabled' => false]);

    $order = terminalOrder($this);
    $reader = terminalSeedReaderRow($this);

    // SSO call FIRST — withHeaders() persists the device bearer as a default
    // header on this test instance and would shadow actingAs() afterwards.
    $this->actingAs($this->fixtures->manager)
        ->postJson("/api/v1/shops/{$this->fixtures->shop->slug}/stripe-terminal/readers", [
            'registration_code' => 'simulated-wpe', 'name' => 'R1',
        ])->assertStatus(409)->assertJsonPath('code', 'STRIPE_TERMINAL_DISABLED');

    $this->withHeaders(terminalPosHeaders($this))
        ->postJson("/api/v1/pos/orders/{$order->id}/stripe-terminal/charge", [
            'peripheral_device_id' => $reader->id,
        ])->assertStatus(409)->assertJsonPath('code', 'STRIPE_TERMINAL_DISABLED');
});

// =============================================================================
// 2. Reader registry
// =============================================================================

it('registers a reader by code, ensuring a per-branch Terminal Location', function () {
    $client = terminalMockClient();

    $client->terminal->locations->shouldReceive('all')->once()
        ->andReturn(Collection::constructFrom(['object' => 'list', 'data' => []]));
    $client->terminal->locations->shouldReceive('create')->once()
        ->andReturnUsing(function (array $params) {
            // JP accounts reject `address` for Terminal Locations — the
            // kanji field is mandatory (verified on the live sandbox).
            expect($params['metadata']['tempo_branch_id'])->toBe((string) test()->fixtures->shop->id)
                ->and($params)->not->toHaveKey('address')
                ->and($params['address_kanji']['country'])->toBe('JP')
                ->and($params['address_kanji']['line1'])->not->toBe('');

            return Location::constructFrom(['id' => 'tml_1', 'object' => 'terminal.location']);
        });
    $client->terminal->readers->shouldReceive('create')->once()
        ->andReturnUsing(function (array $params) {
            expect($params['registration_code'])->toBe('simulated-wpe')
                ->and($params['location'])->toBe('tml_1');

            return terminalReaderObject();
        });
    terminalBindStripe($client);

    $this->actingAs($this->fixtures->manager)
        ->postJson("/api/v1/shops/{$this->fixtures->shop->slug}/stripe-terminal/readers", [
            'registration_code' => 'simulated-wpe', 'name' => 'Counter reader',
        ])->assertCreated()
        ->assertJsonPath('data.stripe_reader_id', 'tmr_sim1')
        ->assertJsonPath('data.stripe_location_id', 'tml_1');

    $row = PeripheralDevice::query()->where('metadata->stripe_reader_id', 'tmr_sim1')->first();

    expect($row)->not->toBeNull()
        ->and($row->type)->toBe('payment_terminal')
        ->and(data_get($row->metadata, 'provider'))->toBe('stripe_terminal')
        ->and((string) $row->branch_id)->toBe((string) $this->fixtures->shop->id);
});

it('re-registering the same physical reader updates the row instead of duplicating', function () {
    terminalSeedReaderRow($this, 'tmr_sim1');

    $client = terminalMockClient();
    $client->terminal->locations->shouldReceive('all')->once()
        ->andReturn(Collection::constructFrom(['object' => 'list', 'data' => [
            ['id' => 'tml_1', 'object' => 'terminal.location', 'metadata' => ['tempo_branch_id' => (string) $this->fixtures->shop->id]],
        ]]));
    $client->terminal->readers->shouldReceive('create')->once()->andReturn(terminalReaderObject('tmr_sim1'));
    terminalBindStripe($client);

    $this->actingAs($this->fixtures->manager)
        ->postJson("/api/v1/shops/{$this->fixtures->shop->slug}/stripe-terminal/readers", [
            'registration_code' => 'simulated-wpe', 'name' => 'Renamed reader',
        ])->assertCreated();

    $rows = PeripheralDevice::query()->where('metadata->stripe_reader_id', 'tmr_sim1')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('Renamed reader');
});

// =============================================================================
// 3. Charge → pending row → webhook settles (the #1125 lifecycle, end-to-end)
// =============================================================================

it('charges via the reader: card_present intent + process + awaiting-async pending row', function () {
    $order = terminalOrder($this);
    $reader = terminalSeedReaderRow($this);

    $client = terminalMockClient();
    $client->paymentIntents->shouldReceive('create')->once()
        ->andReturnUsing(function (array $params) use ($order) {
            expect($params['payment_method_types'])->toBe(['card_present'])
                ->and($params['amount'])->toBe(3000)
                ->and($params['currency'])->toBe('jpy')
                ->and($params['metadata']['order_id'])->toBe((string) $order->id)
                ->and($params['metadata']['flow'])->toBe('full');

            return PaymentIntent::constructFrom(vstIntentObject(
                'pi_term', 3000, 'jpy', 'requires_payment_method', $params['metadata'],
            ));
        });
    $client->terminal->readers->shouldReceive('processPaymentIntent')->once()
        ->with('tmr_sim1', ['payment_intent' => 'pi_term'])
        ->andReturn(terminalReaderObject('tmr_sim1', 'online', 'process_payment_intent'));
    terminalBindStripe($client);

    $this->withHeaders(terminalPosHeaders($this))
        ->postJson("/api/v1/pos/orders/{$order->id}/stripe-terminal/charge", [
            'peripheral_device_id' => $reader->id,
        ])->assertStatus(202)
        ->assertJsonPath('data.payment_intent_id', 'pi_term')
        ->assertJsonPath('data.amount', 3000);

    $row = OrderPayment::query()->where('reference_no', 'pi_term')->first();

    expect($row)->not->toBeNull()
        ->and($row->status->value)->toBe('pending')
        ->and((float) $row->amount)->toBe(3000.0)
        ->and(data_get($row->metadata, 'async_method'))->toBe('card_present')
        // Reader money is not collected money until the webhook says so.
        ->and((float) $order->fresh()->paid_amount)->toBe(0.0);
});

it('the payment_intent.succeeded webhook flips the terminal pending row and settles the order', function () {
    $order = terminalOrder($this);
    $reader = terminalSeedReaderRow($this);

    $client = terminalMockClient();
    $client->paymentIntents->shouldReceive('create')->once()->andReturn(PaymentIntent::constructFrom(vstIntentObject(
        'pi_term', 3000, 'jpy', 'requires_payment_method',
        ['order_id' => $order->id, 'flow' => 'full', 'order_currency' => 'jpy'],
    )));
    $client->terminal->readers->shouldReceive('processPaymentIntent')->once()->andReturn(terminalReaderObject());
    terminalBindStripe($client);

    $this->withHeaders(terminalPosHeaders($this))
        ->postJson("/api/v1/pos/orders/{$order->id}/stripe-terminal/charge", [
            'peripheral_device_id' => $reader->id,
        ])->assertStatus(202);

    // Guest taps → Stripe fires the ordinary succeeded webhook.
    $event = vstSignedEvent('payment_intent.succeeded', vstIntentObject(
        'pi_term', 3000, 'jpy', 'succeeded',
        ['order_id' => $order->id, 'flow' => 'full', 'order_currency' => 'jpy'],
    ));
    $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )->assertOk();

    $rows = OrderPayment::query()->where('customer_order_id', $order->id)->get();
    $fresh = $order->fresh();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->status->value)->toBe('succeeded')
        ->and((float) $fresh->paid_amount)->toBe(3000.0)
        ->and($fresh->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
});

it('a reader refusal cancels the intent and leaves no pending row', function () {
    $order = terminalOrder($this);
    $reader = terminalSeedReaderRow($this);

    $client = terminalMockClient();
    $client->paymentIntents->shouldReceive('create')->once()->andReturn(PaymentIntent::constructFrom(vstIntentObject(
        'pi_term', 3000, 'jpy', 'requires_payment_method', ['order_id' => $order->id, 'flow' => 'full'],
    )));
    $client->terminal->readers->shouldReceive('processPaymentIntent')->once()
        ->andThrow(new InvalidRequestException('Reader is offline.'));
    $client->paymentIntents->shouldReceive('cancel')->once()
        ->andReturn(PaymentIntent::constructFrom(vstIntentObject('pi_term', 3000, 'jpy', 'canceled', [])));
    terminalBindStripe($client);

    $this->withHeaders(terminalPosHeaders($this))
        ->postJson("/api/v1/pos/orders/{$order->id}/stripe-terminal/charge", [
            'peripheral_device_id' => $reader->id,
        ])->assertStatus(422);

    expect(OrderPayment::query()->where('reference_no', 'pi_term')->exists())->toBeFalse();
});

// =============================================================================
// 4. Cancel
// =============================================================================

it('cancel aborts the reader action + intent and fails the pending row', function () {
    $order = terminalOrder($this);
    $reader = terminalSeedReaderRow($this);
    app(OrderPaymentService::class)->recordAsyncPendingPayment(
        (string) $order->id, 'pi_term', 3000.0, asyncMethod: 'card_present', intentStatus: 'requires_payment_method',
    );

    $client = terminalMockClient();
    $client->terminal->readers->shouldReceive('cancelAction')->once()->andReturn(terminalReaderObject());
    $client->paymentIntents->shouldReceive('cancel')->once()
        ->andReturn(PaymentIntent::constructFrom(vstIntentObject('pi_term', 3000, 'jpy', 'canceled', [])));
    terminalBindStripe($client);

    $this->withHeaders(terminalPosHeaders($this))
        ->postJson('/api/v1/pos/stripe-terminal/cancel', [
            'peripheral_device_id' => $reader->id,
            'payment_intent_id' => 'pi_term',
        ])->assertOk();

    expect(OrderPayment::query()->where('reference_no', 'pi_term')->first()->status->value)->toBe('failed');
});

it('cancel NEVER fails the row when the intent already succeeded (guest tapped in the race)', function () {
    $order = terminalOrder($this);
    $reader = terminalSeedReaderRow($this);
    app(OrderPaymentService::class)->recordAsyncPendingPayment(
        (string) $order->id, 'pi_term', 3000.0, asyncMethod: 'card_present', intentStatus: 'requires_payment_method',
    );

    $client = terminalMockClient();
    $client->terminal->readers->shouldReceive('cancelAction')->once()->andReturn(terminalReaderObject());
    $client->paymentIntents->shouldReceive('cancel')->once()
        ->andThrow(new InvalidRequestException('You cannot cancel this PaymentIntent because it has a status of succeeded.'));
    terminalBindStripe($client);

    $this->withHeaders(terminalPosHeaders($this))
        ->postJson('/api/v1/pos/stripe-terminal/cancel', [
            'peripheral_device_id' => $reader->id,
            'payment_intent_id' => 'pi_term',
        ])->assertOk();

    // Stays pending — the succeeded webhook will flip it to real money.
    expect(OrderPayment::query()->where('reference_no', 'pi_term')->first()->status->value)->toBe('pending');
});

// =============================================================================
// 5. Guards
// =============================================================================

it('rejects a reader belonging to another branch and a fully-paid order', function () {
    $order = terminalOrder($this);
    $reader = terminalSeedReaderRow($this);

    // Foreign-branch reader → the POS shop guard 404s before Stripe is touched.
    $foreign = Branch::factory()->create([
        'console_organization_id' => $this->fixtures->organization->id,
        'console_brand_id' => $this->fixtures->brand->console_brand_id,
    ]);
    $reader->update(['branch_id' => $foreign->id]);
    terminalBindStripe(terminalMockClient());

    $this->withHeaders(terminalPosHeaders($this))
        ->postJson("/api/v1/pos/orders/{$order->id}/stripe-terminal/charge", [
            'peripheral_device_id' => $reader->id,
        ])->assertStatus(422);

    // Paid order → nothing to collect.
    $reader->update(['branch_id' => $this->fixtures->shop->id]);
    $order->forceFill(['paid_amount' => 3000])->save();

    $this->withHeaders(terminalPosHeaders($this))
        ->postJson("/api/v1/pos/orders/{$order->id}/stripe-terminal/charge", [
            'peripheral_device_id' => $reader->id,
        ])->assertStatus(422);
});

it('#1643 — recordAsyncPendingPayment ném khi bị đưa model thay vì id', function () {
    // Thu hẹp tham số về `string` trong một file KHÔNG bật `strict_types` là một
    // cái bẫy im lặng: `Model::__toString()` trả JSON, nên model vẫn lọt vào,
    // `find()` trả null, và method trả `false` như thể đơn không tồn tại. Đúng
    // hai test trong file này đã đỏ theo kiểu đó khi dựng #1643 — đỏ ở assertion
    // cuối, không ở lời gọi. Rào này đưa lỗi về đúng chỗ gây ra nó.
    $order = terminalOrder($this);

    expect(fn () => app(OrderPaymentService::class)->recordAsyncPendingPayment(
        $order, 'pi_guard', 100.0, asyncMethod: 'card_present', intentStatus: 'requires_payment_method',
    ))->toThrow(InvalidArgumentException::class);

    expect(OrderPayment::query()->where('reference_no', 'pi_guard')->exists())->toBeFalse();
});
