<?php

/**
 * plan-055 T4.1 / T6.1 / T6.3 (#1823) — the fork on a missing gateway option.
 *
 * Flag OFF is the measurement Gate 3 exits on; flag ON is the enforcement Gate 6
 * turns on. The property that matters most is the second one refusing WITHOUT
 * writing a ledger row: a payment that is going to be rejected must leave no
 * money behind it.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Customer\OrderPaymentService;
use App\Services\Payment\Configuration\Exceptions\PaymentConfigurationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->organizationId,
        'console_organization_id' => $this->organizationId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->organizationId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organizationId,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    $this->operator = User::factory()->create(['console_organization_id' => $this->organizationId]);
    $this->cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'type' => 'cash',
        'is_active' => true,
    ]);

    // #1831 — the refusal cases below must use a GATEWAY-routed method.
    //
    // They were written against `cash`, which encoded the defect: an internal
    // tender can never carry a gateway option id (the internal catalog is
    // appended at the controller layer and never enters the policy snapshot the
    // validator reads), so "cash is refused" meant the flip would refuse every
    // cash payment — the most common tender in both markets. The tests were
    // green and nobody read them as a warning.
    $this->card = PaymentMethod::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'code' => 'card',
        'type' => 'card',
        'is_active' => true,
    ]);
});

function enforcementOrder(array $ctx): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $ctx['org'],
        'brand_id' => $ctx['brand'],
        'branch_id' => $ctx['branch'],
        'status' => 'checkout',
        'total_amount' => 800,
        'paid_amount' => 0,
    ]);
}

function enforcementPayload(CustomerOrder $order, array $ctx, array $extra = []): array
{
    return array_merge([
        'customer_order_id' => $order->id,
        'payment_method_id' => $ctx['method'],
        'amount' => 800,
        'tendered_amount' => 800,
        'received_by_id' => $ctx['operator'],
        'organization_id' => $ctx['org'],
        'brand_id' => $ctx['brand'],
        'branch_id' => $ctx['branch'],
        'orchestrator_transport' => 'pos',
        'device_id' => 'device-under-test',
    ], $extra);
}

it('lets a payment through and logs the miss when enforcement is off', function () {
    config(['payments.policy_enforcement.required' => false]);

    $ctx = ['org' => $this->organizationId, 'brand' => $this->brand->id, 'branch' => $this->branch->id, 'method' => $this->card->id, 'operator' => $this->operator->id];
    $order = enforcementOrder($ctx);

    $logged = [];
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$logged) {
        $logged[] = [$message, $context];
    });
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();

    $payment = app(OrderPaymentService::class)->create(
        enforcementPayload($order, $ctx, ['idempotency_key' => 'policy-off-1'])
    );

    $misses = array_values(array_filter($logged, static fn (array $e): bool => $e[0] === 'payment_policy_option_missing'));

    expect($payment->id)->not->toBeNull()
        ->and($misses)->toHaveCount(1)
        // The exact fields Gate 3 needs to name who breaks on flip.
        ->and($misses[0][1]['transport'])->toBe('pos')
        ->and($misses[0][1]['device_id'])->toBe('device-under-test')
        ->and($misses[0][1]['branch_id'])->toBe((string) $this->branch->id)
        ->and($misses[0][1]['organization_id'])->toBe((string) $this->organizationId);
});

it('refuses with POLICY_OPTION_REQUIRED and writes NO ledger row when enforcement is on', function () {
    config(['payments.policy_enforcement.required' => true]);

    $ctx = ['org' => $this->organizationId, 'brand' => $this->brand->id, 'branch' => $this->branch->id, 'method' => $this->card->id, 'operator' => $this->operator->id];
    $order = enforcementOrder($ctx);

    $before = OrderPayment::query()->count();

    $thrown = null;
    try {
        app(OrderPaymentService::class)->create(
            enforcementPayload($order, $ctx, ['idempotency_key' => 'policy-on-1'])
        );
    } catch (PaymentConfigurationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->errorCode)->toBe('POLICY_OPTION_REQUIRED')
        ->and($thrown->status)->toBe(422)
        ->and($thrown->retryable)->toBeFalse()
        ->and($thrown->action)->toBe('refresh_payment_options')
        // A refused payment must leave no money behind it.
        ->and(OrderPayment::query()->count())->toBe($before)
        ->and((float) $order->fresh()->paid_amount)->toBe(0.0);
});

it('carries the offending transport in the refusal so the client can be identified', function () {
    config(['payments.policy_enforcement.required' => true]);

    $ctx = ['org' => $this->organizationId, 'brand' => $this->brand->id, 'branch' => $this->branch->id, 'method' => $this->card->id, 'operator' => $this->operator->id];
    $order = enforcementOrder($ctx);

    try {
        app(OrderPaymentService::class)->create(
            enforcementPayload($order, $ctx, [
                'orchestrator_transport' => 'workstation',
                'idempotency_key' => 'policy-on-2',
            ])
        );
        $this->fail('Expected PaymentConfigurationException.');
    } catch (PaymentConfigurationException $exception) {
        expect($exception->details['transport'])->toBe('workstation')
            ->and($exception->details['order_id'])->toBe((string) $order->id);
    }
});

it('does not change behaviour for a payment that names its option', function () {
    // With an option id present the submission is non-null, so the flag is not
    // consulted at all — the existing validator owns the decision. Proven by
    // the validator's own error surfacing rather than POLICY_OPTION_REQUIRED.
    config(['payments.policy_enforcement.required' => true]);

    $ctx = ['org' => $this->organizationId, 'brand' => $this->brand->id, 'branch' => $this->branch->id, 'method' => $this->card->id, 'operator' => $this->operator->id];
    $order = enforcementOrder($ctx);

    try {
        app(OrderPaymentService::class)->create(
            enforcementPayload($order, $ctx, [
                'gateway_option_id' => (string) Str::uuid(),
                'policy_revision' => 1,
                'idempotency_key' => 'policy-on-3',
            ])
        );
        $this->fail('Expected the policy validator to reject an unpublished revision.');
    } catch (PaymentConfigurationException $exception) {
        expect($exception->errorCode)->not->toBe('POLICY_OPTION_REQUIRED');
    }
});

/**
 * plan-055 T5.2 (#1826) — an offline-replayed order is waived.
 *
 * The stamp is written by Cloud inside `insertOfflineReplay()`, after the
 * device signature has been verified. A payment belonging to such an order must
 * land even with enforcement on: the cash is already in the till, and refusing
 * it at sync time only produces an orphaned order and a shift that no longer
 * reconciles.
 */
it('waives a payment whose order Cloud stamped as an offline replay', function () {
    config(['payments.policy_enforcement.required' => true]);

    $ctx = ['org' => $this->organizationId, 'brand' => $this->brand->id, 'branch' => $this->branch->id, 'method' => $this->card->id, 'operator' => $this->operator->id];
    $order = enforcementOrder($ctx);
    // Exactly what insertOfflineReplay() does — Cloud stamping a verified fact.
    $order->forceFill(['offline_replayed_at' => now()])->save();

    $logged = [];
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$logged) {
        $logged[] = $message;
    });
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();

    $payment = app(OrderPaymentService::class)->create(
        enforcementPayload($order, $ctx, [
            'orchestrator_transport' => 'workstation',
            'idempotency_key' => 'replay-waived-1',
        ])
    );

    // Deliberately a GATEWAY-routed method (#1831): with cash the internal-tender
    // exemption would let this through too, and the test would pass without the
    // replay waiver existing at all. A card payment is not auto-confirm, so it
    // lands `pending` and `paid_amount` stays 0 — the row being WRITTEN is what
    // proves the waiver ran, not the balance.
    expect($payment->id)->not->toBeNull()
        ->and($logged)->toContain('payment_policy_replay_bypass');
});

/**
 * plan-055 T5.3 (#1826) — the waiver is NOT a new hole.
 *
 * Same transport, same missing option, same flag — the only difference is that
 * Cloud never stamped the order. It must still be refused.
 */
it('still refuses an unstamped order on the same transport', function () {
    config(['payments.policy_enforcement.required' => true]);

    $ctx = ['org' => $this->organizationId, 'brand' => $this->brand->id, 'branch' => $this->branch->id, 'method' => $this->card->id, 'operator' => $this->operator->id];
    $order = enforcementOrder($ctx);

    expect($order->offline_replayed_at)->toBeNull();

    $thrown = null;
    try {
        app(OrderPaymentService::class)->create(
            enforcementPayload($order, $ctx, [
                'orchestrator_transport' => 'workstation',
                'idempotency_key' => 'replay-not-waived-1',
            ])
        );
    } catch (PaymentConfigurationException $exception) {
        $thrown = $exception;
    }

    expect($thrown?->errorCode)->toBe('POLICY_OPTION_REQUIRED')
        ->and((float) $order->fresh()->paid_amount)->toBe(0.0);
});

// The "online path never stamps offline_replayed_at" assertion deliberately does
// NOT live here. Written against a `CustomerOrder::factory()` order it passes no
// matter what the production funnel does — it asserts a factory default, not
// behaviour. It lives in TypedOrderCreateEndToEndTest, on the real create path,
// where forcing the funnel to stamp actually turns it red.
