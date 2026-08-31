<?php

/**
 * CLAIM D2 — chargeback/dispute handling. FLIPPED FROM DOCUMENT-GAP TO PIN
 * on 2026-07-27 (#1123): the dispute lifecycle now moves the ledger.
 *
 *   charge.dispute.created          → payment flagged (metadata), NO money move
 *   charge.dispute.funds_withdrawn  → contra-revenue row (negative amount,
 *                                     refund_of_id), original → refunded,
 *                                     order paid cache follows
 *   charge.dispute.closed (lost)    → withdrawal ensured (idempotent with the
 *                                     funds_withdrawn event)
 *   charge.dispute.funds_reinstated → positive re-credit row, original back to
 *                                     succeeded (dispute won)
 *
 * Events post as correctly-signed webhooks against the REAL route; the inbox
 * job runs inline on the sync queue.
 *
 * The trailing enumeration keeps DOCUMENTING the remaining unhandled
 * money-moving events (fraud signals, refund lifecycle, async intents,
 * payouts) — see #1125 for the async family.
 */

require_once __DIR__.'/vst_helpers.php';

use App\Models\CustomerOrder;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\User;
use App\Omnify\Enums\PaymentStatusEnum;
use Database\Seeders\IamSeeder;
use Database\Seeders\SystemNotificationTemplateSeeder;
use Stripe\StripeClient;

beforeEach(function () {
    vstConfigureStripe(currency: 'jpy');
    vstBindStripe(Mockery::mock(StripeClient::class));

    $this->tenant = vstTenant();
    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $this->tenant['org_id'],
        'brand_id' => $this->tenant['brand']->id,
        'branch_id' => $this->tenant['branch']->id,
        'total_amount' => 5000,
        'paid_amount' => 5000,
        'status' => 'closed',
        'stripe_payment_intent_id' => 'pi_vstD2',
    ]);

    $this->stripeMethod = PaymentMethod::factory()->create([
        'code' => 'stripe',
        'organization_id' => $this->tenant['org_id'],
        'branch_id' => null,
    ]);

    $this->payment = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->stripeMethod->id,
        'branch_id' => $this->tenant['branch']->id,
        'brand_id' => $this->tenant['brand']->id,
        'organization_id' => $this->tenant['org_id'],
        'amount' => 5000,
        'reference_no' => 'pi_vstD2',
    ]);

    $this->postEvent = function (string $type, array $obj) {
        $event = vstSignedEvent($type, $obj);

        return $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
            $event['payload'],
        );
    };

    $this->disputeObj = fn (string $id, string $status, array $over = []) => array_merge([
        'object' => 'dispute',
        'id' => $id,
        'charge' => 'ch_vstD2',
        'payment_intent' => 'pi_vstD2',
        'amount' => 5000,
        'currency' => 'jpy',
        'reason' => 'fraudulent',
        'status' => $status,
    ], $over);
});

// =============================================================================
// The dispute lifecycle now moves the ledger.
// =============================================================================

it('D2 charge.dispute.created flags the payment — no money moves yet', function () {
    ($this->postEvent)('charge.dispute.created', ($this->disputeObj)('dp_vst_1', 'needs_response'))
        ->assertOk()->assertJson(['received' => true]);

    $payment = $this->payment->fresh();
    expect($payment->status)->toBe(PaymentStatusEnum::Succeeded)
        ->and($payment->metadata['stripe_dispute_id'] ?? null)->toBe('dp_vst_1')
        ->and($payment->metadata['stripe_dispute_status'] ?? null)->toBe('needs_response')
        ->and(OrderPayment::where('customer_order_id', $this->order->id)->count())->toBe(1)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(5000.0);
});

it('D2 charge.dispute.funds_withdrawn writes a contra-revenue row and the books follow the bank', function () {
    ($this->postEvent)('charge.dispute.funds_withdrawn', ($this->disputeObj)('dp_vst_2', 'under_review'))
        ->assertOk();

    $rows = OrderPayment::where('customer_order_id', $this->order->id)->orderBy('created_at')->get();
    expect($rows)->toHaveCount(2);

    $contra = $rows->firstWhere('id', '!=', $this->payment->id);
    expect((float) $contra->amount)->toBe(-5000.0)
        ->and($contra->refund_of_id)->toBe($this->payment->id)
        ->and($contra->metadata['stripe_dispute_id'] ?? null)->toBe('dp_vst_2')
        ->and($contra->metadata['dispute_kind'] ?? null)->toBe('withdrawal')
        ->and($this->payment->fresh()->status)->toBe(PaymentStatusEnum::Refunded)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0);

    // Replay of the same dispute event cannot double-reverse.
    ($this->postEvent)('charge.dispute.funds_withdrawn', ($this->disputeObj)('dp_vst_2', 'under_review'))
        ->assertOk();
    expect(OrderPayment::where('customer_order_id', $this->order->id)->count())->toBe(2);
});

it('D2 charge.dispute.closed (lost) ensures the withdrawal is ledgered', function () {
    ($this->postEvent)('charge.dispute.closed', ($this->disputeObj)('dp_vst_3', 'lost'))->assertOk();

    $contra = OrderPayment::where('customer_order_id', $this->order->id)
        ->where('id', '!=', $this->payment->id)->first();

    expect($contra)->not->toBeNull()
        ->and((float) $contra->amount)->toBe(-5000.0)
        ->and($contra->metadata['dispute_kind'] ?? null)->toBe('withdrawal')
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0);
});

it('D2 funds_reinstated after a withdrawal re-credits the ledger — dispute won', function () {
    ($this->postEvent)('charge.dispute.funds_withdrawn', ($this->disputeObj)('dp_vst_4', 'under_review'))
        ->assertOk();
    expect((float) $this->order->fresh()->paid_amount)->toBe(0.0);

    ($this->postEvent)('charge.dispute.funds_reinstated', ($this->disputeObj)('dp_vst_4', 'won'))
        ->assertOk();

    $rows = OrderPayment::where('customer_order_id', $this->order->id)->get();
    $recredit = $rows->first(fn ($p) => ($p->metadata['dispute_kind'] ?? null) === 'reinstatement');

    expect($rows)->toHaveCount(3)
        ->and((float) $recredit->amount)->toBe(5000.0)
        ->and($recredit->refund_of_id)->toBeNull()
        ->and($this->payment->fresh()->status)->toBe(PaymentStatusEnum::Succeeded)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(5000.0);

    // Replay of the reinstatement is idempotent too.
    ($this->postEvent)('charge.dispute.funds_reinstated', ($this->disputeObj)('dp_vst_4', 'won'))
        ->assertOk();
    expect(OrderPayment::where('customer_order_id', $this->order->id)->count())->toBe(3);
});

// =============================================================================
// STILL-DOCUMENTING enumeration: money-moving events with NO handler yet.
// Dispute events left this list on 2026-07-27 (#1123); the async intent
// family is tracked by #1125 (option A shut the door client-side).
// =============================================================================

it('D2 enumerates the REMAINING money-moving Stripe events with no handler — all are silent no-ops', function (string $type, array $obj) {
    $before = OrderPayment::where('customer_order_id', $this->order->id)->get()
        ->map(fn ($p) => [$p->id, (string) $p->amount, $p->status instanceof PaymentStatusEnum ? $p->status->value : $p->status])
        ->toArray();

    ($this->postEvent)($type, $obj)
        ->assertOk()
        ->assertJson(['received' => true]); // Stripe is told "all good"

    $after = OrderPayment::where('customer_order_id', $this->order->id)->get()
        ->map(fn ($p) => [$p->id, (string) $p->amount, $p->status instanceof PaymentStatusEnum ? $p->status->value : $p->status])
        ->toArray();

    expect($after)->toBe($before)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(5000.0);
})->with([
    // ---- FRAUD SIGNALS (pre-chargeback: the window to refund voluntarily) ----
    'radar.early_fraud_warning.created' => ['radar.early_fraud_warning.created', ['object' => 'radar.early_fraud_warning', 'id' => 'issfr_1', 'charge' => 'ch_vstD2', 'payment_intent' => 'pi_vstD2']],
    'review.opened' => ['review.opened', ['object' => 'review', 'id' => 'prv_1', 'payment_intent' => 'pi_vstD2']],
    'review.closed' => ['review.closed', ['object' => 'review', 'id' => 'prv_2', 'payment_intent' => 'pi_vstD2']],

    // ---- REFUND LIFECYCLE (a refund that FAILS silently re-credits us) ----
    'charge.refund.updated' => ['charge.refund.updated', ['object' => 'refund', 'id' => 're_failed', 'payment_intent' => 'pi_vstD2', 'amount' => 5000, 'currency' => 'jpy', 'status' => 'failed']],
    'refund.created' => ['refund.created', ['object' => 'refund', 'id' => 're_c1', 'payment_intent' => 'pi_vstD2', 'amount' => 5000, 'currency' => 'jpy', 'status' => 'succeeded']],
    'refund.updated' => ['refund.updated', ['object' => 'refund', 'id' => 're_u1', 'payment_intent' => 'pi_vstD2', 'amount' => 5000, 'currency' => 'jpy', 'status' => 'failed']],
    'refund.failed' => ['refund.failed', ['object' => 'refund', 'id' => 're_f1', 'payment_intent' => 'pi_vstD2', 'amount' => 5000, 'currency' => 'jpy', 'status' => 'failed']],

    // ---- ASYNC / FAILED PAYMENT LIFECYCLE (#1125 — option A shut the door) ----
    'payment_intent.processing' => ['payment_intent.processing', ['object' => 'payment_intent', 'id' => 'pi_vstD2', 'amount' => 5000, 'currency' => 'jpy', 'status' => 'processing', 'metadata' => ['flow' => 'full']]],
    'payment_intent.payment_failed' => ['payment_intent.payment_failed', ['object' => 'payment_intent', 'id' => 'pi_vstD2', 'amount' => 5000, 'currency' => 'jpy', 'status' => 'requires_payment_method', 'metadata' => ['flow' => 'full']]],
    'payment_intent.canceled' => ['payment_intent.canceled', ['object' => 'payment_intent', 'id' => 'pi_vstD2', 'amount' => 5000, 'currency' => 'jpy', 'status' => 'canceled', 'metadata' => ['flow' => 'full']]],
    'payment_intent.requires_action' => ['payment_intent.requires_action', ['object' => 'payment_intent', 'id' => 'pi_vstD2', 'amount' => 5000, 'currency' => 'jpy', 'status' => 'requires_action', 'metadata' => ['flow' => 'full']]],
    'payment_intent.partially_funded' => ['payment_intent.partially_funded', ['object' => 'payment_intent', 'id' => 'pi_vstD2', 'amount' => 5000, 'currency' => 'jpy', 'status' => 'requires_action', 'metadata' => ['flow' => 'full']]],

    // ---- CHARGE-LEVEL ----
    'charge.failed' => ['charge.failed', ['object' => 'charge', 'id' => 'ch_f', 'payment_intent' => 'pi_vstD2', 'amount' => 5000, 'currency' => 'jpy', 'status' => 'failed']],
    'charge.expired' => ['charge.expired', ['object' => 'charge', 'id' => 'ch_e', 'payment_intent' => 'pi_vstD2', 'amount' => 5000, 'currency' => 'jpy']],

    // ---- PAYOUT (settlement to the restaurant's bank) ----
    'payout.failed' => ['payout.failed', ['object' => 'payout', 'id' => 'po_1', 'amount' => 5000, 'currency' => 'jpy', 'status' => 'failed']],
]);

// =============================================================================
// #1123 remainder — manager notification per dispute phase (plan-023 infra).
// The earlier tests run with NO manager assigned and still pass → the
// notification is fail-open by construction; this one pins the happy path.
// =============================================================================

it('D2 notifies the shop-manager on open and on funds withdrawal — idempotent per phase', function () {
    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }
    (new SystemNotificationTemplateSeeder)->run();

    $manager = User::factory()->create([
        'console_organization_id' => $this->tenant['org_id'],
    ]);
    $manager->assignRole('shop-manager', $this->tenant['org_id'], $this->tenant['branch']->id);

    ($this->postEvent)('charge.dispute.created', ($this->disputeObj)('dp_vst_n1', 'needs_response'))->assertOk();
    ($this->postEvent)('charge.dispute.funds_withdrawn', ($this->disputeObj)('dp_vst_n1', 'under_review'))->assertOk();
    // Replay must not duplicate the alert (idempotency_key per dispute+phase).
    ($this->postEvent)('charge.dispute.funds_withdrawn', ($this->disputeObj)('dp_vst_n1', 'under_review'))->assertOk();

    $notifications = Notification::query()->where('type', 'payment.disputed')->get();
    expect($notifications)->toHaveCount(2)
        ->and($notifications->pluck('idempotency_key')->sort()->values()->all())->toBe([
            'payment.disputed:dp_vst_n1:funds_withdrawn',
            'payment.disputed:dp_vst_n1:opened',
        ]);

    $recipientUserIds = NotificationRecipient::query()
        ->whereIn('notification_id', $notifications->pluck('id'))
        ->pluck('recipient_id')->unique()->values();
    expect($recipientUserIds->all())->toBe([$manager->id]);
});
