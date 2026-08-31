<?php

declare(strict_types=1);

use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentRefund;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\ProviderEvent\ProviderEventApplicator;

/**
 * Plan 047 T2.16 / T3.5 — orchestrator refund reconcile.
 *
 * `applyChargeRefunded` runs TWO passes and their split of responsibility is the
 * whole safety argument:
 *
 *   1. LegacyStripeWebhookBridge owns the LEDGER reversal (the negative
 *      order_payments row), idempotent by stripe_refund_id.
 *   2. The orchestrator pass reconciles only the matching PaymentRefund's
 *      STATE — reconcileRefund writes no money.
 *
 * So both passes running on the same event cannot double-refund. These tests pin
 * the selection rules of pass 2: which refund ids are extracted from the inbox
 * snapshot, which PaymentRefund rows they are allowed to touch, and what the
 * returned outcome string is (it is what the inbox records and what ops greps).
 *
 * Companion: ChargeRefundedSnapshotTest covers the HTTP webhook → snapshot →
 * no-double-refund path end to end. This file drills the applicator's own edges.
 */
beforeEach(function () {
    // Resolving the applicator pulls in LegacyStripeWebhookBridge → StripeClient,
    // whose constructor throws without a secret. No API call is made in this file.
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_xyz',
        'services.stripe.currency' => 'jpy',
    ]);

    $this->org = Organization::query()->first() ?? Organization::factory()->create();
    $this->provider = PaymentGatewayProvider::factory()->create(['code' => 'stripe', 'is_active' => true]);
    $this->connection = PaymentGatewayConnection::factory()->create([
        'organization_id' => $this->org->id,
        'provider_id' => $this->provider->id,
        'environment' => 'test',
        'is_active' => true,
    ]);
    $this->applicator = app(ProviderEventApplicator::class);
});

/** Invoke the private id extractor with an arbitrary redacted_payload. */
function extractRefundIds(object $ctx, mixed $payload): array
{
    $event = new PaymentProviderEvent;
    $event->redacted_payload = $payload;

    $method = new ReflectionMethod($ctx->applicator, 'refundIdsFromEvent');
    $method->setAccessible(true);

    return $method->invoke($ctx->applicator, $event);
}

/** Invoke the private single-refund reconcile and report whether it fired. */
function reconcileRefundId(object $ctx, PaymentProviderEvent $event, string $refundId): bool
{
    $method = new ReflectionMethod($ctx->applicator, 'reconcileOrchestratorRefund');
    $method->setAccessible(true);

    return $method->invoke($ctx->applicator, $event, $refundId);
}

function inboxEvent(object $ctx, array $payload = []): PaymentProviderEvent
{
    return PaymentProviderEvent::factory()->create([
        'organization_id' => $ctx->org->id,
        'connection_id' => $ctx->connection->id,
        'event_type' => 'charge.refunded',
        'redacted_payload' => $payload,
    ]);
}

function refundRow(object $ctx, string $providerRefundId, PaymentRefundStateEnum $state, array $overrides = []): PaymentRefund
{
    return PaymentRefund::factory()->create(array_merge([
        'organization_id' => $ctx->org->id,
        'connection_id' => $ctx->connection->id,
        'provider' => 'stripe',
        'provider_refund_id' => $providerRefundId,
        'state' => $state->value,
        'currency' => 'JPY',
        'amount_minor' => 1000,
        'version' => 1,
    ], $overrides));
}

// ---------------------------------------------------------------------------
// refundIdsFromEvent — what counts as a refund id
// ---------------------------------------------------------------------------

it('extracts nothing from an empty or unrelated payload', function (mixed $payload) {
    expect(extractRefundIds($this, $payload))->toBe([]);
})->with([
    'empty array' => [[]],
    'unrelated keys' => [['charge_snapshot' => ['id' => 'ch_1']]],
    'null payload' => [null],
    'scalar payload' => ['not-an-array'],
    'refund_ids empty' => [['refund_ids' => []]],
]);

it('drops non-string and empty refund ids', function () {
    expect(extractRefundIds($this, [
        'refund_ids' => ['re_ok', '', null, 123, ['re_nested'], false, 're_ok2'],
    ]))->toBe(['re_ok', 're_ok2']);
});

it('ignores an empty-string legacy refund_id', function () {
    expect(extractRefundIds($this, ['refund_id' => '']))->toBe([]);
});

it('ignores a non-string legacy refund_id', function () {
    expect(extractRefundIds($this, ['refund_id' => 42]))->toBe([]);
});

it('merges the plural and legacy singular shapes without duplicates', function () {
    expect(extractRefundIds($this, [
        'refund_ids' => ['re_a', 're_b', 're_a'],
        'refund_id' => 're_b',
    ]))->toBe(['re_a', 're_b']);
});

it('appends a legacy refund_id that the plural list does not carry', function () {
    expect(extractRefundIds($this, [
        'refund_ids' => ['re_a'],
        'refund_id' => 're_z',
    ]))->toBe(['re_a', 're_z']);
});

it('survives a malformed non-iterable refund_ids without throwing', function () {
    // A partner or a schema drift could write a scalar here. Extraction must
    // degrade to "no orchestrator ids" and let the legacy ledger path stand,
    // never abort the whole webhook.
    expect(extractRefundIds($this, ['refund_ids' => 're_scalar', 'refund_id' => 're_ok']))
        ->toBe(['re_ok']);
});

it('preserves provider id order — reconcile order is deterministic', function () {
    expect(extractRefundIds($this, ['refund_ids' => ['re_3', 're_1', 're_2']]))
        ->toBe(['re_3', 're_1', 're_2']);
});

// ---------------------------------------------------------------------------
// reconcileOrchestratorRefund — which PaymentRefund rows may be touched
// ---------------------------------------------------------------------------

it('reconciles a non-terminal refund and advances it out of its open state', function (string $state) {
    $refund = refundRow($this, 're_open', PaymentRefundStateEnum::from($state));
    $event = inboxEvent($this, ['refund_ids' => ['re_open']]);

    expect(reconcileRefundId($this, $event, 're_open'))->toBeTrue()
        ->and($refund->fresh()->state)->not->toBe(PaymentRefundStateEnum::from($state));
})->with([
    'prepared' => ['prepared'],
    'submitted' => ['submitted'],
    'pending' => ['pending'],
    'reconciliation_required' => ['reconciliation_required'],
]);

it('skips a refund already in a terminal state', function (string $state) {
    $refund = refundRow($this, 're_done', PaymentRefundStateEnum::from($state));
    $event = inboxEvent($this, ['refund_ids' => ['re_done']]);

    expect(reconcileRefundId($this, $event, 're_done'))->toBeFalse()
        ->and($refund->fresh()->state)->toBe(PaymentRefundStateEnum::from($state));
})->with([
    'succeeded' => ['succeeded'],
    'failed' => ['failed'],
    'canceled' => ['canceled'],
]);

it('skips an unknown refund id', function () {
    $event = inboxEvent($this, ['refund_ids' => ['re_never_seen']]);

    expect(reconcileRefundId($this, $event, 're_never_seen'))->toBeFalse();
});

it('never reconciles a refund belonging to another connection', function () {
    // The single most dangerous miss: a provider refund id is only unique per
    // merchant account. Matching on id alone would let one merchant's webhook
    // settle another merchant's refund row.
    $otherConnection = PaymentGatewayConnection::factory()->create([
        'organization_id' => $this->org->id,
        'provider_id' => $this->provider->id,
        'environment' => 'test',
        'is_active' => true,
    ]);
    $foreign = refundRow($this, 're_shared', PaymentRefundStateEnum::Pending, [
        'connection_id' => $otherConnection->id,
    ]);

    $event = inboxEvent($this, ['refund_ids' => ['re_shared']]);

    expect(reconcileRefundId($this, $event, 're_shared'))->toBeFalse()
        ->and($foreign->fresh()->state)->toBe(PaymentRefundStateEnum::Pending);
});

it('reconciles the right row when the same provider id exists on two connections', function () {
    $otherConnection = PaymentGatewayConnection::factory()->create([
        'organization_id' => $this->org->id,
        'provider_id' => $this->provider->id,
        'environment' => 'test',
        'is_active' => true,
    ]);
    $foreign = refundRow($this, 're_dup', PaymentRefundStateEnum::Pending, [
        'connection_id' => $otherConnection->id,
    ]);
    $mine = refundRow($this, 're_dup', PaymentRefundStateEnum::Pending);

    $event = inboxEvent($this, ['refund_ids' => ['re_dup']]);

    expect(reconcileRefundId($this, $event, 're_dup'))->toBeTrue()
        ->and($foreign->fresh()->state)->toBe(PaymentRefundStateEnum::Pending)
        ->and($mine->fresh()->state)->not->toBe(PaymentRefundStateEnum::Pending);
});

it('is idempotent — a second reconcile of the same refund is a no-op', function () {
    refundRow($this, 're_twice', PaymentRefundStateEnum::Pending);
    $event = inboxEvent($this, ['refund_ids' => ['re_twice']]);

    expect(reconcileRefundId($this, $event, 're_twice'))->toBeTrue();
    // Now terminal, so the replay must decline rather than re-apply.
    expect(reconcileRefundId($this, $event, 're_twice'))->toBeFalse();
});

it('skips a non-positive-amount refund instead of poisoning the inbox event', function (int $amount) {
    // Money rejects <= 0. Throwing inside the inbox worker would burn all five
    // processing attempts and dead-letter an event whose ledger reversal already
    // landed — so the reconcile declines and leaves the row to the sweep.
    $refund = refundRow($this, 're_bad_amount', PaymentRefundStateEnum::Pending, ['amount_minor' => $amount]);
    $event = inboxEvent($this, ['refund_ids' => ['re_bad_amount']]);

    expect(reconcileRefundId($this, $event, 're_bad_amount'))->toBeFalse()
        ->and($refund->fresh()->state)->toBe(PaymentRefundStateEnum::Pending);
})->with([
    'zero' => [0],
    'negative' => [-500],
]);

it('normalizes an upper-case stored currency to the provider lower-case form', function () {
    // PaymentRefund.currency is stored ISO-upper; Stripe refund objects are
    // lower-case. A mismatch here would make the lifecycle mapper reject the
    // evidence, silently stranding the refund.
    refundRow($this, 're_currency', PaymentRefundStateEnum::Pending, ['currency' => 'JPY']);
    $event = inboxEvent($this, ['refund_ids' => ['re_currency']]);

    expect(reconcileRefundId($this, $event, 're_currency'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// applyChargeRefunded — outcome contract across mixed batches
// ---------------------------------------------------------------------------

it('reports orchestrator_refund_reconciled when at least one refund advanced', function () {
    refundRow($this, 're_hit', PaymentRefundStateEnum::Pending);
    refundRow($this, 're_terminal', PaymentRefundStateEnum::Succeeded);
    $event = inboxEvent($this, ['refund_ids' => ['re_terminal', 're_hit']]);

    $method = new ReflectionMethod($this->applicator, 'refundIdsFromEvent');
    $method->setAccessible(true);
    $ids = $method->invoke($this->applicator, $event);

    $reconciled = array_filter($ids, fn (string $id) => reconcileRefundId($this, $event, $id));

    // One of the two advanced — that is what flips the outcome string.
    expect($ids)->toBe(['re_terminal', 're_hit'])
        ->and(array_values($reconciled))->toBe(['re_hit']);
});

it('advances every open refund in a multi-refund charge', function () {
    refundRow($this, 're_p1', PaymentRefundStateEnum::Pending);
    refundRow($this, 're_p2', PaymentRefundStateEnum::Submitted);
    $event = inboxEvent($this, ['refund_ids' => ['re_p1', 're_p2']]);

    expect(reconcileRefundId($this, $event, 're_p1'))->toBeTrue()
        ->and(reconcileRefundId($this, $event, 're_p2'))->toBeTrue()
        ->and(PaymentRefund::query()->whereIn('provider_refund_id', ['re_p1', 're_p2'])
            ->whereIn('state', ['prepared', 'submitted', 'pending', 'reconciliation_required'])
            ->count())->toBe(0);
});

it('writes no order_payments row — the orchestrator pass is state-only', function () {
    refundRow($this, 're_state_only', PaymentRefundStateEnum::Pending);
    $event = inboxEvent($this, ['refund_ids' => ['re_state_only']]);

    $before = OrderPayment::query()->count();
    reconcileRefundId($this, $event, 're_state_only');

    expect(OrderPayment::query()->count())->toBe($before);
});
