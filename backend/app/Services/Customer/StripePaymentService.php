<?php

namespace App\Services\Customer;

use App\Exceptions\OverpaymentRejectedException;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\StampOrderStripeIntentCommand;
use App\Services\Order\Contracts\BranchCurrency;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Order\Enums\OrderSplitMode;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\Gateway\Stripe\StripePaymentGateway;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Internal\OrderPaymentIntentPointer;
use App\Services\Payment\Orchestration\Internal\CustomerWebStripeConnectionResolver;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use App\Services\Payment\Orchestration\ValueObjects\OrderRef;
use App\Services\Payment\ProviderEvent\GatewayConnectionDataFactory;
use App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection;
use App\Services\Payment\Reconciliation\MoneyReconciliationQueue;
use App\Support\ZeroDecimalCurrency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Stripe\Charge;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

class StripePaymentService
{
    private StripePaymentGateway $gateway;

    private OrderPaymentOrchestrationCompat $orchestrationCompat;

    private ?GatewayConnectionData $legacyConnection = null;

    public function __construct(
        StripePaymentGateway|StripeClient|null $gatewayOrClient = null,
        ?OrderPaymentOrchestrationCompat $orchestrationCompat = null,
    ) {
        if ($gatewayOrClient instanceof StripeClient) {
            $this->gateway = new StripePaymentGateway($gatewayOrClient);
        } elseif ($gatewayOrClient instanceof StripePaymentGateway) {
            $this->gateway = $gatewayOrClient;
        } else {
            $this->gateway = app(StripePaymentGateway::class);
        }

        $this->orchestrationCompat = $orchestrationCompat ?? app(OrderPaymentOrchestrationCompat::class);
    }

    /**
     * This service is the LEGACY global-platform Stripe path (single config
     * STRIPE_SECRET, no Connect merchant routing). Every raw gateway call is
     * scoped explicitly to that legacy connection so the adapter applies the
     * correct (platform, i.e. empty) Connect scope — a Connect merchant order
     * must go through the typed orchestrator commands, never this service.
     */
    private function legacyConnection(): GatewayConnectionData
    {
        return $this->legacyConnection ??= (new LegacyGlobalStripeConnection)->connectionData();
    }

    /** @var array<string, GatewayConnectionData> per-order resolution cache (one policy walk per request) */
    private array $orderConnectionCache = [];

    /** Plan-048 T2.5 — the policy identity customer-web SAW (checkout mount). */
    private ?int $clientPolicyRevision = null;

    private ?string $clientGatewayOptionId = null;

    /**
     * Plan-048 T2.5 — record the `policy_revision` + `gateway_option_id` the
     * client echoed on the intent call. Purely observational: the server's own
     * resolution stays authoritative; a mismatch is logged as
     * `customer_web_policy_drift` so ops can see stale-policy checkouts
     * (customer opened the page, admin republished, customer paid).
     */
    public function withClientPolicyHint(?int $revision, ?string $optionId): static
    {
        $this->clientPolicyRevision = $revision;
        $this->clientGatewayOptionId = $optionId;

        return $this;
    }

    private function resolvedRoutingEnabled(): bool
    {
        return (bool) config('payments.stripe_customer_web_resolved_connection', true);
    }

    /**
     * Plan-048 Gate 2 (#1081) — the connection every Stripe call for this
     * ORDER must run under. Resolves the real brand connection through the
     * payment policy (HQ/franchise ownership + Connect scope); branches with
     * no policy-backed Stripe option fall back to the legacy global
     * connection, so live card acceptance never regresses mid-migration.
     * The resolver logs every fallback, which is the Gate 2 cutover metric.
     */
    private function connectionForOrder(CustomerOrder $order): GatewayConnectionData
    {
        if (! $this->resolvedRoutingEnabled()) {
            return $this->legacyConnection();
        }

        $key = (string) $order->id;
        if (isset($this->orderConnectionCache[$key])) {
            return $this->orderConnectionCache[$key];
        }

        try {
            $refs = app(CustomerWebStripeConnectionResolver::class)->resolveForOrder(new OrderRef((string) $order->id, (string) $order->organization_id, $order->brand_id === null ? null : (string) $order->brand_id, (string) $order->branch_id));
            $this->logClientPolicyDrift($order, $refs);
            $row = PaymentGatewayConnection::query()->find($refs['connectionId']);
            if ($row !== null) {
                return $this->orderConnectionCache[$key] = GatewayConnectionDataFactory::fromModel($row);
            }
        } catch (\Throwable $exception) {
            Log::warning('customer_web_stripe_connection_resolution_failed', [
                'order_id' => (string) $order->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $this->orderConnectionCache[$key] = $this->legacyConnection();
    }

    /**
     * Plan-048 T2.5 — compare the client-echoed policy identity against what
     * the server actually resolved. Log-only: a stale client NEVER changes the
     * charge (the resolved refs stay authoritative), it just leaves a
     * `customer_web_policy_drift` breadcrumb on the orchestration channel.
     *
     * @param  array{connectionId: string, connectionOptionId: string, optionId: string, policyRevision: int}  $refs
     */
    private function logClientPolicyDrift(CustomerOrder $order, array $refs): void
    {
        if ($this->clientPolicyRevision === null && $this->clientGatewayOptionId === null) {
            return;
        }

        $revisionMatches = $this->clientPolicyRevision === null
            || $this->clientPolicyRevision === (int) $refs['policyRevision'];
        $optionMatches = $this->clientGatewayOptionId === null
            || $this->clientGatewayOptionId === (string) $refs['optionId'];
        if ($revisionMatches && $optionMatches) {
            return;
        }

        Log::channel('payment_orchestration')->info('customer_web_policy_drift', [
            'order_id' => (string) $order->id,
            'branch_id' => (string) $order->branch_id,
            'client_policy_revision' => $this->clientPolicyRevision,
            'client_gateway_option_id' => $this->clientGatewayOptionId,
            'server_policy_revision' => (int) $refs['policyRevision'],
            'server_gateway_option_id' => (string) $refs['optionId'],
        ]);
    }

    /**
     * The connection an EXISTING intent was created under — retrieve, cancel,
     * confirm, and refund must run in the same Connect scope or Stripe 404s.
     * Resolved from the durable PaymentAttempt the prepare step reserved;
     * intents with no attempt (pre-Gate-2 history) stay on legacy.
     */
    private function connectionForIntent(string $paymentIntentId): GatewayConnectionData
    {
        if (! $this->resolvedRoutingEnabled()) {
            return $this->legacyConnection();
        }

        $connectionId = PaymentAttempt::query()
            ->where('provider_object_id', $paymentIntentId)
            ->value('connection_id');
        $row = $connectionId === null ? null : PaymentGatewayConnection::query()->find($connectionId);

        return $row !== null ? GatewayConnectionDataFactory::fromModel($row) : $this->legacyConnection();
    }

    /**
     * #815 — the currency the order was PRICED in, and therefore the ONLY currency
     * Stripe may charge: the branch's ShopOrderSetting.currency_code. The global
     * config('services.stripe.currency') is ONLY a fallback for a branch that has no
     * currency configured — it never overrides the branch currency.
     */
    private function resolveOrderCurrency(CustomerOrder $order): string
    {
        $code = app(BranchCurrency::class)->codeFor((string) $order->branch_id);

        return strtoupper((string) ($code ?: config('services.stripe.currency', 'jpy')));
    }

    /**
     * #815 — read-only accessor used by the currency-mismatch audit command
     * (stripe:audit-currency-mismatch) to inspect the actually-charged currency of a
     * historical intent. Kept thin so tests can bind a fake StripeClient and assert
     * only `paymentIntents->retrieve` was called.
     */
    public function retrieveIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->gateway->retrievePaymentIntent($paymentIntentId, $this->connectionForIntent($paymentIntentId));
    }

    public function retrieveCharge(string $chargeId): Charge
    {
        return $this->gateway->retrieveCharge($chargeId, $this->legacyConnection());
    }

    /**
     * Create or reuse a PaymentIntent for the given order.
     * Returns client_secret + publishable_key for frontend.
     *
     * @return array{client_secret: string, publishable_key: string, currency: string, amount: int, payment_intent_id: string}
     */
    public function createOrRetrievePaymentIntent(CustomerOrder $order): array
    {
        $currency = $this->resolveOrderCurrency($order);
        $amount = $this->toMinorUnits((float) $order->total_amount, $currency);

        if ($amount < 1) {
            throw new RuntimeException('Order amount must be greater than zero.');
        }

        $intent = null;

        if ($order->stripe_payment_intent_id) {
            try {
                $intent = $this->gateway->retrievePaymentIntent($order->stripe_payment_intent_id, $this->connectionForOrder($order));

                // #815 — currency drift: a stored intent created before this branch's
                // currency was resolved (legacy jpy) or before an admin currency flip
                // carries the OLD currency. Stripe forbids mutating an intent's
                // currency, and VND/JPY are both zero-decimal so the amount-equality
                // check below cannot distinguish them — so drop the intent (cancel if
                // cancelable) and recreate BEFORE the amount-update branch.
                if ($intent !== null && strtolower((string) $intent->currency) !== strtolower($currency)) {
                    try {
                        if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing'], true)) {
                            $this->gateway->cancelPaymentIntent($intent->id, $this->connectionForOrder($order));
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Stripe cancel stale-currency intent failed', ['id' => $intent->id, 'error' => $e->getMessage()]);
                    }
                    $intent = null;
                }

                if ($intent !== null) {
                    // If the stored intent is no longer usable (e.g. canceled or already succeeded
                    // but for a different amount), drop it and create a new one.
                    $reusable = in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing'], true);

                    if ($reusable && $intent->amount !== $amount) {
                        $intent = $this->gateway->updatePaymentIntent($intent->id, [
                            'amount' => $amount,
                        ], $this->connectionForOrder($order));
                    } elseif (! $reusable && $intent->status !== 'succeeded') {
                        $intent = null;
                    } elseif ($reusable) {
                        $this->orchestrationCompat->prepareStripePaymentIntent(
                            $order,
                            (string) $intent->id,
                            $amount,
                            $currency,
                            'retrieve',
                        );
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Stripe retrieve PaymentIntent failed', ['id' => $order->stripe_payment_intent_id, 'error' => $e->getMessage()]);
                $intent = null;
            }
        }

        if ($intent === null) {
            $intent = $this->gateway->createPaymentIntent([
                'amount' => $amount,
                'currency' => strtolower($currency),
                // #1125 — card-only by default (option-A posture); option B opens
                // Stripe dynamic payment methods behind payments.async_payment_methods.
                ...self::browserIntentMethodParams(),
                'metadata' => [
                    'order_id' => $order->id,
                    'order_code' => (string) $order->order_code,
                    'branch_id' => (string) $order->branch_id,
                    'organization_id' => (string) $order->organization_id,
                    // #815 — immutable snapshot of the charge currency so the webhook
                    // ledger guard can assert charge == priced without re-reading the
                    // (mutable) live branch setting.
                    'order_currency' => strtolower($currency),
                ],
            ], $this->connectionForOrder($order));

            $this->stampStripeIntentOnOrder($order, $intent->id);
        }

        $this->orchestrationCompat->prepareStripePaymentIntent(
            $order,
            (string) $intent->id,
            $amount,
            $currency,
            'full',
        );

        return [
            'client_secret' => (string) $intent->client_secret,
            'publishable_key' => (string) config('services.stripe.key'),
            'currency' => strtolower($currency),
            'amount' => $amount,
            'payment_intent_id' => $intent->id,
        ];
    }

    /**
     * Create a fresh PaymentIntent that charges the remaining balance
     * (`total_amount - paid_amount`). Used by customer-web Stripe checkout
     * when the customer selects "Pay full remaining". If no prior split
     * payments exist, this equals total_amount.
     *
     * Any prior pending intent stored on the order is cancelled so Stripe
     * doesn't keep multiple half-finished intents tied to one order_id.
     *
     * @return array{client_secret: string, publishable_key: string, currency: string, amount: int, payment_intent_id: string}
     */
    public function createFullPaymentIntent(CustomerOrder $order): array
    {
        $currency = $this->resolveOrderCurrency($order);
        $remaining = max(0, (float) $order->total_amount - (float) $order->paid_amount);
        $amount = $this->toMinorUnits($remaining, $currency);

        if ($amount < 1) {
            throw new RuntimeException('Order amount must be greater than zero.');
        }

        // Check if we already have a valid PaymentIntent for this order.
        // Reuse it if it's still in a usable state to avoid creating duplicates.
        if ($order->stripe_payment_intent_id) {
            try {
                $existing = $this->gateway->retrievePaymentIntent($order->stripe_payment_intent_id, $this->connectionForOrder($order));

                // If intent is still pending (user can retry payment with same intent), reuse it.
                // #815 — the currency match is REQUIRED alongside amount: VND and JPY are
                // both zero-decimal, so a legacy jpy intent and a vnd order can share the
                // same integer minor amount (250000) — without this a stale-currency intent
                // would be wrongly reused. A mismatch falls through to cancel + recreate.
                $reusableStatuses = ['requires_payment_method', 'requires_confirmation', 'requires_action'];
                if (in_array($existing->status, $reusableStatuses, true)
                    && $existing->amount === $amount
                    && strtolower((string) $existing->currency) === strtolower($currency)) {
                    $this->orchestrationCompat->prepareStripePaymentIntent(
                        $order,
                        (string) $existing->id,
                        $amount,
                        $currency,
                        'full',
                    );

                    Log::info('Stripe PaymentIntent reused', [
                        'order_id' => $order->id,
                        'payment_intent_id' => $existing->id,
                        'status' => $existing->status,
                    ]);

                    return [
                        'client_secret' => (string) $existing->client_secret,
                        'publishable_key' => (string) config('services.stripe.key'),
                        'currency' => strtolower($currency),
                        'amount' => $amount,
                        'payment_intent_id' => $existing->id,
                    ];
                }

                // If intent succeeded or canceled, we need a new one (fall through to create).
                // If amount changed, also create new one.
                if (in_array($existing->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing'], true)) {
                    $this->gateway->cancelPaymentIntent($existing->id, $this->connectionForOrder($order));
                    Log::info('Stripe PaymentIntent canceled (amount changed or stale)', [
                        'order_id' => $order->id,
                        'old_intent_id' => $existing->id,
                        'old_amount' => $existing->amount,
                        'new_amount' => $amount,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Stripe retrieve/cancel stale intent failed', [
                    'order_id' => $order->id,
                    'intent_id' => $order->stripe_payment_intent_id,
                    'error' => $e->getMessage(),
                ]);
                // Fall through to create new intent
            }
        }

        $intent = $this->gateway->createPaymentIntent([
            'amount' => $amount,
            'currency' => strtolower($currency),
            // #1125 — card-only by default (option-A posture); option B opens
            // Stripe dynamic payment methods behind payments.async_payment_methods.
            ...self::browserIntentMethodParams(),
            'metadata' => [
                'order_id' => $order->id,
                'order_code' => (string) $order->order_code,
                'branch_id' => (string) $order->branch_id,
                'organization_id' => (string) $order->organization_id,
                'flow' => 'full',
                'order_currency' => strtolower($currency), // #815 — immutable charge-currency snapshot
            ],
        ], $this->connectionForOrder($order));

        $this->stampStripeIntentOnOrder($order, $intent->id);

        $this->orchestrationCompat->prepareStripePaymentIntent(
            $order,
            (string) $intent->id,
            $amount,
            $currency,
            'full',
        );

        Log::info('Stripe PaymentIntent created', [
            'order_id' => $order->id,
            'payment_intent_id' => $intent->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $intent->status,
        ]);

        return [
            'client_secret' => (string) $intent->client_secret,
            'publishable_key' => (string) config('services.stripe.key'),
            'currency' => strtolower($currency),
            'amount' => $amount,
            'payment_intent_id' => $intent->id,
        ];
    }

    /**
     * The same mint, but owning the lock and the remaining-balance check that
     * the method below only ever ASKED its callers to provide (#1666).
     *
     * Its docblock says "ideally inside a DB transaction with lockForUpdate()
     * on the order" — a requirement stated as advice, which
     * `CustomerOrderController::createSplitPaymentIntent` happened to follow and
     * anyone adding a second caller would have had to notice. Without the lock,
     * two payers on one bill each read the same `paid_amount` and each pass the
     * "does not exceed remaining" check, so a ¥3,000 bill mints two ¥3,000
     * intents.
     *
     * @return array{client_secret: string, publishable_key: string, currency: string, amount: int, payment_intent_id: string}
     */
    public function createSplitPaymentIntentUnderLock(
        string $orderId,
        float $amount,
        ?int $splitCount = null,
        ?string $splitType = null,
        ?array $itemAllocations = null,
        ?string $idempotencyKey = null
    ): array {
        return DB::transaction(function () use ($orderId, $amount, $splitCount, $splitType, $itemAllocations, $idempotencyKey): array {
            $locked = CustomerOrder::lockForUpdate()->findOrFail($orderId);

            $remaining = (float) $locked->total_amount - (float) $locked->paid_amount;

            if ($amount > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => ["Amount exceeds remaining balance. Remaining: {$remaining}"],
                ]);
            }

            return $this->createSplitPaymentIntent(
                $locked,
                $amount,
                $splitCount,
                $splitType,
                $itemAllocations,
                $idempotencyKey,
            );
        });
    }

    /**
     * Create a PaymentIntent for a partial (split-bill) amount. Unlike
     * createFullPaymentIntent(), this one:
     *  - charges a custom amount (≤ remaining balance)
     *  - does NOT cancel prior intents (multiple people may pay concurrently)
     *  - does NOT store the intent ID on the order (one order → many intents)
     *  - tags metadata.flow = 'split' so the webhook handler routes correctly
     *
     * The caller MUST validate $amount ≤ remaining BEFORE calling this,
     * ideally inside a DB transaction with lockForUpdate() on the order.
     *
     * If $splitCount is provided → first payment in "Chia đều" flow → store
     * split_count + amount_per_person in metadata to lock all subsequent payers
     * to the same count and amount.
     *
     * @return array{client_secret: string, publishable_key: string, currency: string, amount: int, payment_intent_id: string}
     */
    public function createSplitPaymentIntent(
        CustomerOrder $order,
        float $amount,
        ?int $splitCount = null,
        ?string $splitType = null,
        ?array $itemAllocations = null,
        ?string $idempotencyKey = null
    ): array {
        $currency = $this->resolveOrderCurrency($order);
        $minorAmount = $this->toMinorUnits($amount, $currency);

        if ($minorAmount < 1) {
            throw new RuntimeException('Amount must be greater than zero.');
        }

        $metadata = [
            'order_id' => $order->id,
            'order_code' => (string) $order->order_code,
            'branch_id' => (string) $order->branch_id,
            'organization_id' => (string) $order->organization_id,
            'flow' => 'split',
            'order_currency' => strtolower($currency), // #815 — immutable charge-currency snapshot
        ];

        // First payment in split-even flow → ghi split_count + amount_per_person
        // để lock subsequent payers
        if ($splitCount !== null && $splitCount >= 2) {
            $metadata['split_count'] = (string) $splitCount;
            $metadata['split_type'] = OrderSplitMode::canonicalWire($splitType) ?? OrderSplitMode::Even->value;
            // Lưu amount_per_person (major units, float) để FE hiển thị consistent
            $metadata['amount_per_person'] = (string) $amount;
        }

        // Chia theo món: stamp the per-item allocation so the recorded payment
        // can be attributed to specific order items. Stripe metadata values are
        // strings ≤ 500 chars, so we store a COMPACT pair form `[["<item_id>",
        // units], ...]` (≈45 chars/item → ~11 items per payment, well above the
        // typical "pay for my own 1–3 dishes" case). OrderPaymentService::recordStripeWebhookPayment()
        // expands this back to the {item_id, units} shape formatOrder() reads.
        if ($splitType === 'by_items' && is_array($itemAllocations) && $itemAllocations !== []) {
            $pairs = [];
            foreach ($itemAllocations as $alloc) {
                $itemId = (string) ($alloc['item_id'] ?? '');
                $units = (int) ($alloc['units'] ?? 0);
                if ($itemId !== '' && $units > 0) {
                    $pairs[] = [$itemId, $units];
                }
            }

            if ($pairs !== []) {
                $encoded = json_encode($pairs);
                // Stripe rejects metadata values over 500 chars. Guard rather
                // than let the intent-create throw an opaque API error — drop
                // the attribution (payment still records as an amount) and log
                // so the overflow case is visible instead of silently 500-ing.
                if (is_string($encoded) && strlen($encoded) <= 500) {
                    $metadata['split_mode'] = 'by_items';
                    $metadata['item_allocations'] = $encoded;
                } else {
                    Log::warning('Split by_items allocation too large for Stripe metadata; recording amount only', [
                        'order_id' => $order->id,
                        'item_count' => count($pairs),
                        'encoded_len' => is_string($encoded) ? strlen($encoded) : null,
                    ]);
                }
            }
        }

        // #555 M10 — scope the client-supplied key per order so two orders
        // reusing the same client key can never collide on Stripe's
        // account-global idempotency namespace.
        $intent = $this->gateway->createPaymentIntent([
            'amount' => $minorAmount,
            'currency' => strtolower($currency),
            // #1125 — card-only by default (option-A posture); option B opens
            // Stripe dynamic payment methods behind payments.async_payment_methods.
            ...self::browserIntentMethodParams(),
            'metadata' => $metadata,
        ], $this->connectionForOrder($order), $idempotencyKey === null ? null : "pi-split-{$order->id}-{$idempotencyKey}");

        $this->orchestrationCompat->prepareStripePaymentIntent(
            $order,
            (string) $intent->id,
            $minorAmount,
            $currency,
            'split',
        );

        return [
            'client_secret' => (string) $intent->client_secret,
            'publishable_key' => (string) config('services.stripe.key'),
            'currency' => strtolower($currency),
            'amount' => $minorAmount,
            'payment_intent_id' => $intent->id,
        ];
    }

    /**
     * #1125 — payment-method parameters for every browser PaymentIntent.
     *
     * Flag OFF (default): card-only — the option-A hotfix posture, pinned by
     * ClaimD3AsyncMethodsTest. Flag ON: Stripe dynamic payment methods
     * (automatic_payment_methods) with redirect flows excluded — the
     * Dashboard decides which methods (Konbini, 銀行振込…) each guest sees.
     * Konbini vouchers are bounded by konbini_expires_after_days so expiry
     * stays inside the order's payment window.
     *
     * @return array<string, mixed>
     */
    public static function browserIntentMethodParams(): array
    {
        if (! config('payments.async_payment_methods.enabled', false)) {
            return ['payment_method_types' => ['card']];
        }

        return [
            'automatic_payment_methods' => [
                'enabled' => true,
                // The pay page confirms with redirect "if_required" but has no
                // return-trip handling for redirect-based methods — exclude them.
                'allow_redirects' => 'never',
            ],
            'payment_method_options' => [
                'konbini' => [
                    'expires_after_days' => max(1, min(60, (int) config('payments.async_payment_methods.konbini_expires_after_days', 3))),
                ],
            ],
        ];
    }

    /**
     * #1125 option B — is this intent parked in an ASYNC pending state the
     * ledger should track (voucher printed / funds in transit), as opposed to
     * an interactive action the payer still owes (3DS)?
     */
    public static function isAsyncPendingIntent(PaymentIntent $intent): bool
    {
        if ($intent->status === 'processing') {
            return true;
        }

        if ($intent->status !== 'requires_action') {
            return false;
        }

        $nextActionType = (string) ($intent->next_action->type ?? '');

        return in_array($nextActionType, [
            'konbini_display_details',
            'display_bank_transfer_instructions',
            'oxxo_display_details',
            'boleto_display_details',
            'multibanco_display_details',
        ], true);
    }

    /**
     * Confirm endpoint driver — one authoritative retrieve, then either the
     * synchronous succeeded path or async pending tracking (#1125 option B).
     *
     * @return array{state: string, order: CustomerOrder|null}
     */
    public function confirmOutcome(string $paymentIntentId): array
    {
        $intent = $this->gateway->retrievePaymentIntent($paymentIntentId, $this->connectionForIntent($paymentIntentId));

        if ($intent->status === 'succeeded') {
            return ['state' => 'succeeded', 'order' => $this->confirmSucceededIntent($intent)];
        }

        if (self::isAsyncPendingIntent($intent)) {
            $order = $this->trackAsyncPendingFromIntent($intent);

            if ($order !== null) {
                return ['state' => 'awaiting_async_payment', 'order' => $order];
            }
        }

        throw new RuntimeException("PaymentIntent {$paymentIntentId} has not succeeded (status: {$intent->status}).");
    }

    /**
     * #1125 option B — record the awaiting-async ledger row for an intent in
     * `processing` / voucher-displayed state. Resolves the order by
     * metadata.order_id first (survives a D1 pointer overwrite), then the
     * stored pointer. Idempotent per intent id.
     */
    public function trackAsyncPendingFromIntent(PaymentIntent $intent): ?CustomerOrder
    {
        $order = null;
        $metadataOrderId = (string) ($intent->metadata->order_id ?? '');
        if ($metadataOrderId !== '') {
            $order = CustomerOrder::find($metadataOrderId);
        }
        // #1611 — tra ngược qua bảng của PAYMENTS, không quét cột trên bảng
        // của Ordering. Cột cũ vẫn được ghi song song ở giai đoạn *migrate*
        // (xem OrderPaymentIntentPointer), nên nó còn dùng được làm đường đọc
        // dự phòng cho tới bước *contract* thôi ghi rồi drop cột.
        $order ??= ($mappedId = app(OrderPaymentIntentPointer::class)->orderIdFor((string) $intent->id)) !== null
            ? CustomerOrder::find($mappedId)
            : CustomerOrder::where('stripe_payment_intent_id', $intent->id)->first();

        if ($order === null) {
            return null;
        }

        $currency = strtoupper((string) ($intent->currency ?: $this->resolveOrderCurrency($order)));

        app(OrderPaymentService::class)->recordAsyncPendingPayment(
            (string) $order->id,
            (string) $intent->id,
            $this->fromMinorUnits($intent->amount, $currency),
            asyncMethod: (string) ($intent->next_action->type ?? ($intent->payment_method_types[0] ?? 'unknown')),
            intentStatus: (string) $intent->status,
            expiresAt: $this->asyncVoucherExpiry($intent),
        );

        return $order->refresh();
    }

    /** Voucher/instruction expiry (unix → ISO-8601) when the next_action carries one. */
    private function asyncVoucherExpiry(PaymentIntent $intent): ?string
    {
        $expiresAt = $intent->next_action->konbini_display_details->expires_at
            ?? $intent->next_action->display_bank_transfer_instructions->hosted_instructions_url_expires_at
            ?? null;

        return is_numeric($expiresAt)
            ? CarbonImmutable::createFromTimestampUTC((int) $expiresAt)->toIso8601String()
            : null;
    }

    /**
     * #1125 option B — money-safe release of an order's async intent before
     * the overdue reaper expires the order. Returns FALSE when the order must
     * NOT be expired (the intent already succeeded — money arrived / is
     * arriving; let the webhook settle it) or when Stripe is unreachable
     * (never kill an order while its voucher might still be live).
     */
    public function releaseAsyncIntentBeforeExpiry(CustomerOrder $order): bool
    {
        $intentId = (string) ($order->stripe_payment_intent_id ?? '');
        if ($intentId === '') {
            return true;
        }

        $hasPendingRow = OrderPayment::query()
            ->where('reference_no', $intentId)
            ->where('status', PaymentStatusEnum::Pending->value)
            ->exists();

        if (! $hasPendingRow) {
            return true;
        }

        try {
            $intent = $this->gateway->retrievePaymentIntent($intentId, $this->connectionForIntent($intentId));

            if ($intent->status === 'succeeded') {
                return false;
            }

            if ($intent->status !== 'canceled') {
                $this->gateway->cancelPaymentIntent($intentId, $this->connectionForIntent($intentId));
            }
        } catch (\Throwable $e) {
            Log::channel('payment_orchestration')->warning('async_intent_release_failed', [
                'order_id' => (string) $order->id,
                'payment_intent_id' => $intentId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        app(OrderPaymentService::class)->failAsyncPendingPayment($intentId, 'expired');

        return true;
    }

    /**
     * Synchronously confirm a succeeded PaymentIntent server-side and mark the
     * order paid — the same work the webhook does, but triggered by the client
     * immediately after Stripe.js finishes confirming the card.
     *
     * This removes the hard dependency on the Stripe CLI listener / webhook
     * delivery: in local dev the admin shows the order as paid without anyone
     * running `stripe listen`, and in production it's a fast-path that doesn't
     * wait for webhook round-trip latency. The webhook remains the safety net
     * for the "customer closed the tab before this call" case.
     *
     * Idempotent: markOrderPaidFromIntent() keys on order_payments.reference_no
     * = $intent->id, so whichever of {this call, the webhook} lands first wins
     * and the other is a no-op. We re-fetch the intent from Stripe (never trust
     * a client-supplied status) so a tampered request can't mark an order paid.
     *
     * @throws RuntimeException if the intent has not actually succeeded.
     * @throws OverpaymentRejectedException if the card was charged but the
     *                                      overpayment guard refused to ledger the slice (concurrent
     *                                      full/split payment already collected the balance). The payer must
     *                                      get a clear non-200 + a refund, never a silent 200.
     */
    public function confirmAndRecordPayment(string $paymentIntentId): ?CustomerOrder
    {
        $intent = $this->gateway->retrievePaymentIntent($paymentIntentId, $this->connectionForIntent($paymentIntentId));

        if ($intent->status !== 'succeeded') {
            throw new RuntimeException("PaymentIntent {$paymentIntentId} has not succeeded (status: {$intent->status}).");
        }

        return $this->confirmSucceededIntent($intent);
    }

    /** The post-retrieve half of {@see confirmAndRecordPayment} — also driven by confirmOutcome(). */
    private function confirmSucceededIntent(PaymentIntent $intent): ?CustomerOrder
    {
        $order = $this->markOrderPaidFromIntent($intent);

        // Overpayment-reject detection (MONEY). The card has succeeded, so the
        // payer's money left their account. markOrderPaidFromIntent() records a
        // row keyed on reference_no = $intent->id for EVERY path that keeps the
        // charge (happy path + idempotent replay of an already-ledgered intent).
        // The ONLY way a succeeded intent produces no such row is the
        // overpayment guard refusing to ledger it — the exact "charged but not
        // credited" case. markOrderPaidFromIntent() has ALREADY auto-issued the
        // Stripe refund for that stranded slice (idempotent on the intent id).
        // On the webhook path the rejection is a silent no-op to Stripe (it must
        // get its 200), but the synchronous payer must NOT get a 200: surface a
        // clear non-200 so customer-web can render "refund pending" (now true),
        // and log the intent id at error level for the reconciliation queue.
        if ($order !== null) {
            $ledgered = OrderPayment::query()
                ->where('customer_order_id', $order->id)
                ->where('reference_no', $intent->id)
                ->exists();

            if (! $ledgered) {
                // #815 — the charged currency is the intent's own; fall back to the
                // order's priced currency (never the global config) if ever absent.
                $currency = strtoupper((string) ($intent->currency ?: $this->resolveOrderCurrency($order)));
                $chargedAmount = $this->fromMinorUnits($intent->amount, $currency);

                // #1206 — tagged so alerting can match it; the level was
                // already right.
                Log::error('[payments.stranded] Overpayment charge stranded — refund required (synchronous confirm)', [
                    'order_id' => $order->id,
                    'order_code' => $order->order_code,
                    'payment_intent_id' => $intent->id,
                    'charged_amount' => $chargedAmount,
                    'total_amount' => (float) $order->total_amount,
                    'paid_amount' => (float) $order->paid_amount,
                ]);

                // #1206 — the log says money is stranded; this makes it
                // countable. The auto-refund this path relies on is itself
                // gated (that gating is #1206's subject), so the charge can
                // genuinely still be sitting at the gateway. The relay will
                // NOT move it back on its own — returning money without a human
                // deciding to is not its authority — but the row makes the debt
                // durable and attributable, and the stale alert makes sure
                // somebody sees it.
                app(MoneyReconciliationQueue::class)->enqueue(
                    MoneyReconciliationQueue::TYPE_STRANDED_CHARGE,
                    MoneyReconciliationQueue::SUBJECT_PAYMENT_INTENT,
                    MoneyReconciliationQueue::subjectIdForGatewayReference((string) $intent->id),
                    [
                        'payment_intent_id' => (string) $intent->id,
                        'customer_order_id' => (string) $order->id,
                        'order_code' => (string) $order->order_code,
                        'charged_amount' => $chargedAmount,
                        'currency_code' => $currency,
                        'origin' => 'synchronous_confirm',
                    ],
                    (string) $order->organization_id,
                    $order->branch_id === null ? null : (string) $order->branch_id,
                    'overpayment rejected — charge not ledgered',
                );

                throw new OverpaymentRejectedException(
                    paymentIntentId: $intent->id,
                    orderId: (string) $order->id,
                    chargedAmount: $chargedAmount,
                );
            }
        }

        return $order;
    }

    /**
     * Verify webhook signature and return the decoded event.
     */
    public function verifyWebhook(string $payload, string $signature): Event
    {
        $connection = app(LegacyGlobalStripeConnection::class)->connectionData();

        try {
            $this->gateway->verifyWebhook(new VerifyWebhookCommand(
                $connection,
                $payload,
                ['Stripe-Signature' => $signature],
                'legacy:stripe-webhook:'.hash('sha256', $payload),
            ));
        } catch (WebhookVerificationFailed $e) {
            throw new RuntimeException('Invalid Stripe signature: '.$e->getMessage(), 0, $e);
        }

        /** @var array<string, mixed> $eventData */
        $eventData = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        return Event::constructFrom($eventData);
    }

    /**
     * Mark the matching order as paid + record the Stripe payment as an
     * OrderPayment row so admin / reconciliation views can render method =
     * Stripe + paid_at + paid_amount the same way they render cash/card/etc.
     *
     * Routes by `metadata.flow`. BOTH flows now increment paid_amount by the
     * ACTUAL charged slice under a per-order lock and reject any slice that
     * would push the collected total past total_amount — paid_amount is never
     * hardcoded to total_amount (that would clamp the order and hide a real
     * card overpayment when a full and a split intent race):
     *  - `full`  → charges the remaining; closes when paid_amount ≥ total_amount
     *  - `split` → charges a custom slice; closes only when fully paid
     *
     * Idempotent — safe to call on replayed webhooks. Idempotency is keyed
     * on `order_payments.reference_no = $intent->id` (Stripe payment intent
     * IDs are globally unique).
     */
    public function markOrderPaidFromIntent(PaymentIntent $intent): ?CustomerOrder
    {
        $flow = data_get($intent, 'metadata.flow', 'full');

        if ($flow === 'split') {
            return $this->handleSplitPaymentWebhook($intent);
        }

        // #1611 — như trên: bảng Payments trước, cột cũ chỉ là lưới an toàn
        // cho đơn chưa qua backfill.
        $order = ($mappedId = app(OrderPaymentIntentPointer::class)->orderIdFor((string) $intent->id)) !== null
            ? CustomerOrder::find($mappedId)
            : CustomerOrder::where('stripe_payment_intent_id', $intent->id)->first();

        // #1125 option B (D3+D1 tail) — a konbini/bank-transfer paid HOURS
        // later may find the pointer already overwritten by a newer intent.
        // The immutable metadata.order_id snapshot still names the order; the
        // guards below (idempotency, currency, overpayment) decide whether the
        // late money may ledger — a lookup miss must never be what drops it.
        if (! $order) {
            $metadataOrderId = (string) ($intent->metadata->order_id ?? '');
            $order = $metadataOrderId !== '' ? CustomerOrder::find($metadataOrderId) : null;
        }

        if (! $order) {
            return null;
        }

        // Set when the overpayment guard refuses this slice — the card was
        // captured but nothing was ledgered, so the money must be returned. We
        // issue the Stripe refund AFTER the transaction commits (never hold the
        // per-order row lock across an external API round-trip).
        $strandedOverpaymentAmount = null;
        // #815 — set when the ledger guard refuses a charge whose currency ≠ the
        // order's priced currency; same "captured but not credited" case, refund after commit.
        $strandedMismatchAmount = null;

        DB::transaction(function () use ($order, $intent, &$strandedOverpaymentAmount, &$strandedMismatchAmount): void {
            $lockedOrder = CustomerOrder::lockForUpdate()->find($order->id);

            // Record the ACTUAL charged amount, not order.total_amount. The
            // "full" intent charges remaining (total - prior paid), so when
            // a split-bill order is closed by a final full payment the row
            // must show that final slice (e.g. ¥2,991), not the whole ¥5,982.
            // #815 — currency is the intent's own charged currency; fall back to the
            // order's priced currency (never the global config) if ever absent.
            $currency = strtoupper((string) ($intent->currency ?: $this->resolveOrderCurrency($lockedOrder)));
            $paymentAmount = $this->fromMinorUnits($intent->amount, $currency);

            // Idempotency FIRST — a replayed webhook for an intent already
            // ledgered is a no-op, and this must precede the overpayment guard
            // so the re-sum below never counts this intent's own row.
            // #1125 option B — a PENDING row is NOT "already processed": it is
            // the awaiting-async placeholder this very event settles. Only a
            // non-pending row short-circuits; the pending one is flipped to
            // succeeded downstream (recordStripeWebhookPayment) after the
            // currency + overpayment guards have re-run.
            $existingRow = OrderPayment::query()
                ->where('customer_order_id', $lockedOrder->id)
                ->where('reference_no', $intent->id)
                ->first();

            if ($existingRow !== null && $existingRow->status !== PaymentStatusEnum::Pending) {
                return; // Duplicate webhook — already processed
            }

            // #815 — currency mismatch guard (defense in depth). Runs AFTER the
            // idempotency check so a legitimately replayed webhook (already ledgered)
            // is a no-op and is NEVER re-evaluated or re-refunded — nothing already on
            // the books is ever auto-refunded. Expected currency is the immutable
            // metadata snapshot stamped at intent creation; only a legacy pre-#815
            // intent (no snapshot) falls back to the live branch setting. A mismatch
            // means the card was charged in the wrong currency: refuse to ledger and
            // flag the slice for a post-commit refund.
            $expectedCurrency = $intent->metadata->order_currency ?? null;
            if ($expectedCurrency === null || $expectedCurrency === '') {
                $expectedCurrency = $this->resolveOrderCurrency($lockedOrder);
            }

            // Compare the EFFECTIVE charge currency ($currency, resolved above and
            // used to compute $paymentAmount) — not the raw intent field — so the
            // guard and the ledger amount agree.
            if (strtolower($currency) !== strtolower((string) $expectedCurrency)) {
                Log::error('[payments.stranded] Stripe currency mismatch — refusing to ledger', [
                    'order_id' => $lockedOrder->id,
                    'order_code' => $lockedOrder->order_code,
                    'payment_intent_id' => $intent->id,
                    'intent_currency' => strtolower($currency),
                    'expected_currency' => strtolower((string) $expectedCurrency),
                ]);

                $strandedMismatchAmount = $paymentAmount;

                return;
            }

            // Overpayment guard — SAME invariant the split flow enforces. A
            // "full" intent charges the remaining computed at CREATION time; if
            // a concurrent split payment lands between creation and this
            // webhook, blindly setting paid_amount = total_amount would clamp
            // the order to total and HIDE the real card overpayment (the ledger
            // would sum past total). Re-sum the authoritative ledger under the
            // lock and reject any slice that would push collected past total.
            $ledgerPaid = (float) OrderPayment::query()
                ->where('customer_order_id', $lockedOrder->id)
                ->whereIn('status', [
                    PaymentStatusEnum::Succeeded->value,
                    PaymentStatusEnum::Refunded->value,
                ])
                ->sum('amount');
            $currentPaid = max($ledgerPaid, (float) $lockedOrder->paid_amount);
            $outstanding = (float) $lockedOrder->total_amount - $currentPaid;

            if ($paymentAmount - $outstanding > self::OVERPAY_EPSILON) {
                // Do NOT record — keep collected ≤ total_amount. The card was
                // charged (webhook fires post-charge), so log loudly for
                // reconciliation / refund follow-up; ledger integrity
                // (paid_amount never exceeds total) is the invariant we protect.
                Log::warning('Full payment rejected — would exceed order total (overpayment race)', [
                    'order_id' => $lockedOrder->id,
                    'order_code' => $lockedOrder->order_code,
                    'payment_intent_id' => $intent->id,
                    'payment_amount' => $paymentAmount,
                    'already_paid' => $currentPaid,
                    'total_amount' => (float) $lockedOrder->total_amount,
                    'outstanding' => max($outstanding, 0.0),
                ]);

                // The card was captured but not credited — flag the slice for
                // an auto-refund once we're out of the lock/transaction.
                $strandedOverpaymentAmount = $paymentAmount;

                return;
            }

            $wasRecorded = app(OrderPaymentService::class)->recordStripeWebhookPayment(
                $lockedOrder,
                $intent->id,
                $paymentAmount,
                'full',
                self::normalizeIntentMetadata($intent),
                $this->resolveStripeAttemptId($lockedOrder, $intent),
            );

            if (! $wasRecorded) {
                return; // Duplicate webhook — already processed (defensive)
            }

            $this->finalizeStripeAttemptIfRouted($lockedOrder, $intent);

            app(OrderPaymentService::class)->syncLedgerCacheAndSettleIfPaid(
                $lockedOrder,
                idempotencyKey: 'settle-stripe-full-'.$intent->id,
                referenceNo: $intent->id,
            );
        });

        if ($strandedOverpaymentAmount !== null) {
            $this->refundStrandedCharge($intent, $strandedOverpaymentAmount, 'overpay_'.$intent->id, $order);
        }

        if ($strandedMismatchAmount !== null) {
            $this->refundStrandedCharge($intent, $strandedMismatchAmount, 'mismatch_'.$intent->id, $order);
        }

        return $order->refresh();
    }

    /**
     * Sub-unit slack that only absorbs float noise on two-decimal currencies
     * (e.g. 0.005 rounding) — never large enough to mask a real ≥1-unit
     * overpayment. Zero-decimal currencies (JPY/VND) carry integer amounts so
     * the comparison is already exact.
     */
    private const OVERPAY_EPSILON = 0.01;

    /**
     * Handle a split-bill webhook: increment paid_amount by the intent
     * amount, close the order only when fully paid.
     *
     * Uses lockForUpdate() to prevent two concurrent webhooks from both
     * reading the same paid_amount and double-counting.
     *
     * plan-020 overpayment race (MONEY): the split-intent-creation guard in
     * CustomerOrderController only validates `amount ≤ total − paid_amount`,
     * but an online split PaymentIntent moves NOTHING into paid_amount until it
     * confirms here. Two guests scanning the same QR can each create an intent
     * for the full remaining before either confirms — both pass creation-time
     * validation against the same `paid_amount`, both cards charge, and both
     * webhooks land. Previously this method blindly did `paid_amount +=
     * amount`, so the second slice pushed the collected total past
     * `total_amount` (real card overpayment). We now re-sum the authoritative
     * ledger UNDER the per-order lock and reject any slice that would exceed
     * the order total: the first webhook commits its row, the second serialises
     * behind the lock, re-sums, sees the first's row, and is rejected instead
     * of overpaying.
     */
    private function handleSplitPaymentWebhook(PaymentIntent $intent): ?CustomerOrder
    {
        $orderId = $intent->metadata->order_id ?? null;

        if (! $orderId) {
            return null;
        }

        $order = CustomerOrder::find($orderId);

        if (! $order) {
            return null;
        }

        // Set when the overpayment guard refuses this slice — captured card,
        // nothing ledgered → refund after the transaction commits.
        $strandedOverpaymentAmount = null;
        // #815 — set when the currency guard refuses a wrong-currency charge.
        $strandedMismatchAmount = null;

        DB::transaction(function () use ($order, $intent, &$strandedOverpaymentAmount, &$strandedMismatchAmount): void {
            $lockedOrder = CustomerOrder::lockForUpdate()->find($order->id);

            // #815 — intent's own charged currency; order-priced fallback, never config.
            $currency = strtoupper((string) ($intent->currency ?: $this->resolveOrderCurrency($lockedOrder)));
            $paymentAmount = $this->fromMinorUnits($intent->amount, $currency);

            // Idempotency FIRST — a replayed webhook for an intent we already
            // ledgered is a no-op. This must precede the overpayment guard
            // below: the re-sum would otherwise include this intent's own row
            // and misread the replay as an overpayment, wrongly rejecting it.
            // #1125 option B — a PENDING (awaiting-async) row is settled by
            // this event, not a duplicate of it; only non-pending rows bail.
            $existingRow = OrderPayment::query()
                ->where('customer_order_id', $lockedOrder->id)
                ->where('reference_no', $intent->id)
                ->first();

            if ($existingRow !== null && $existingRow->status !== PaymentStatusEnum::Pending) {
                return; // Duplicate webhook — already processed
            }

            // #815 — currency mismatch guard (see markOrderPaidFromIntent for the
            // full rationale). AFTER idempotency so replays are no-ops; metadata-first
            // expected currency, live fallback only for legacy intents. Refuse + flag
            // for post-commit refund on mismatch.
            $expectedCurrency = $intent->metadata->order_currency ?? null;
            if ($expectedCurrency === null || $expectedCurrency === '') {
                $expectedCurrency = $this->resolveOrderCurrency($lockedOrder);
            }

            // Compare the EFFECTIVE charge currency ($currency) — see the full path.
            if (strtolower($currency) !== strtolower((string) $expectedCurrency)) {
                Log::error('[payments.stranded] Stripe currency mismatch — refusing to ledger (split)', [
                    'order_id' => $lockedOrder->id,
                    'order_code' => $lockedOrder->order_code,
                    'payment_intent_id' => $intent->id,
                    'intent_currency' => strtolower($currency),
                    'expected_currency' => strtolower((string) $expectedCurrency),
                ]);

                $strandedMismatchAmount = $paymentAmount;

                return;
            }

            // Overpayment race guard. Re-sum the net collected amount from the
            // ledger (succeeded + refunded rows — the same set
            // updateOrderPaymentCache sums) under the lock, and fall back to
            // the higher of that vs the cached paid_amount so neither a
            // cache-ahead nor a ledger-ahead drift can let a slice slip past.
            // Reject when this slice would push the total over `total_amount`.
            $ledgerPaid = (float) OrderPayment::query()
                ->where('customer_order_id', $lockedOrder->id)
                ->whereIn('status', [
                    PaymentStatusEnum::Succeeded->value,
                    PaymentStatusEnum::Refunded->value,
                ])
                ->sum('amount');
            $currentPaid = max($ledgerPaid, (float) $lockedOrder->paid_amount);
            $outstanding = (float) $lockedOrder->total_amount - $currentPaid;

            if ($paymentAmount - $outstanding > self::OVERPAY_EPSILON) {
                // Do NOT record — keep collected ≤ total_amount. The card was
                // charged (webhook fires post-charge), so this is logged loudly
                // for reconciliation / refund follow-up; the ledger integrity
                // (paid_amount never exceeds total) is the invariant we protect.
                Log::warning('Split payment rejected — would exceed order total (overpayment race)', [
                    'order_id' => $lockedOrder->id,
                    'order_code' => $lockedOrder->order_code,
                    'payment_intent_id' => $intent->id,
                    'payment_amount' => $paymentAmount,
                    'already_paid' => $currentPaid,
                    'total_amount' => (float) $lockedOrder->total_amount,
                    'outstanding' => max($outstanding, 0.0),
                ]);

                // The card was captured but not credited — flag the slice for
                // an auto-refund once we're out of the lock/transaction.
                $strandedOverpaymentAmount = $paymentAmount;

                return;
            }

            $wasRecorded = app(OrderPaymentService::class)->recordStripeWebhookPayment(
                $lockedOrder,
                $intent->id,
                $paymentAmount,
                'split',
                self::normalizeIntentMetadata($intent),
                $this->resolveStripeAttemptId($lockedOrder, $intent),
            );

            if (! $wasRecorded) {
                return; // Duplicate webhook — already processed (defensive)
            }

            $this->finalizeStripeAttemptIfRouted($lockedOrder, $intent);

            app(OrderPaymentService::class)->syncLedgerCacheAndSettleIfPaid(
                $lockedOrder,
                idempotencyKey: 'settle-stripe-split-'.$intent->id,
                referenceNo: $intent->id,
            );

            $lockedOrder->refresh();

            Log::info('Split payment recorded', [
                'order_id' => $lockedOrder->id,
                'order_code' => $lockedOrder->order_code,
                'payment_amount' => $paymentAmount,
                'new_paid_amount' => (float) $lockedOrder->paid_amount,
                'total_amount' => (float) $lockedOrder->total_amount,
                'remaining' => max(0, (float) $lockedOrder->total_amount - (float) $lockedOrder->paid_amount),
                // #962 — hỏi Ordering qua cổng thay vì gọi static sang
                // `OrderClosingService`. Cổng chuyển tiếp đúng phép tính cũ, và
                // `$lockedOrder` vừa `refresh()` ngay trên nên hàng đọc lại là
                // hàng vừa ghi.
                'is_fully_paid' => app(OrderQueryPort::class)->isPaidInFull(
                    (string) $lockedOrder->organization_id,
                    (string) $lockedOrder->id,
                ),
                'payment_intent_id' => $intent->id,
            ]);
        });

        if ($strandedOverpaymentAmount !== null) {
            $this->refundStrandedCharge($intent, $strandedOverpaymentAmount, 'overpay_'.$intent->id, $order);
        }

        if ($strandedMismatchAmount !== null) {
            $this->refundStrandedCharge($intent, $strandedMismatchAmount, 'mismatch_'.$intent->id, $order);
        }

        return $order->refresh();
    }

    /**
     * Normalize Stripe PaymentIntent metadata for OrderPayment.metadata.
     *
     * @return array<string, mixed>
     */
    public static function normalizeIntentMetadata(PaymentIntent $intent): array
    {
        $intentMetadata = [];
        if ($intent->metadata !== null) {
            $intentMetadata = $intent->metadata->toArray();
            // #2865 — intent mint TRƯỚC deploy mang tên cũ trong metadata của
            // chính Stripe. Webhook confirm SAU deploy sẽ ghi chúng vào DB sau
            // khi migration đã chạy, tức một dòng vi phạm bất biến "DB chỉ chứa
            // canonical" — sinh ra bởi chính lượt thống nhất từ vựng.
            foreach (['split_mode', 'split_type'] as $splitKey) {
                if (isset($intentMetadata[$splitKey]) && is_string($intentMetadata[$splitKey])) {
                    $intentMetadata[$splitKey] = OrderSplitMode::canonicalWire($intentMetadata[$splitKey]);
                }
            }
            if (isset($intentMetadata['split_count'])) {
                $intentMetadata['split_count'] = (int) $intentMetadata['split_count'];
            }
            if (isset($intentMetadata['amount_per_person'])) {
                $intentMetadata['amount_per_person'] = (float) $intentMetadata['amount_per_person'];
            }
            if (isset($intentMetadata['item_allocations']) && is_string($intentMetadata['item_allocations'])) {
                $decoded = json_decode($intentMetadata['item_allocations'], true);
                $allocations = [];
                if (is_array($decoded)) {
                    foreach ($decoded as $pair) {
                        if (is_array($pair) && isset($pair[0], $pair[1])) {
                            $allocations[] = [
                                'item_id' => (string) $pair[0],
                                'units' => (int) $pair[1],
                            ];
                        }
                    }
                }

                if ($allocations !== []) {
                    $intentMetadata['item_allocations'] = $allocations;
                } else {
                    unset($intentMetadata['item_allocations'], $intentMetadata['split_mode']);
                }
            }
        }

        return $intentMetadata;
    }

    private function stampStripeIntentOnOrder(CustomerOrder $order, string $paymentIntentId): void
    {
        app(OrderMutationFacade::class)->stampStripeIntent(new StampOrderStripeIntentCommand(
            new MutationContext(
                organizationId: (string) $order->organization_id,
                actorId: null,
                correlationId: (string) Str::uuid(),
                idempotencyKey: 'stripe-intent-'.$paymentIntentId,
                expectedVersion: 1,
            ),
            $order->id,
            $paymentIntentId,
        ));

        // #1611 — GHI CẢ HAI CHỖ. `order_payment_intents` là chỗ đúng (bảng của
        // Payments, khoá theo `(provider, intent_id)` nên nhiều gateway sống
        // chung được); `customer_orders.stripe_payment_intent_id` là chỗ cũ —
        // dữ liệu của Payments nằm trên bảng của Ordering, tên cột còn nhúng
        // luôn tên MỘT gateway.
        //
        // Cột cũ vẫn được ghi ở giai đoạn này, cố ý: bỏ dual-write cùng lúc với
        // chuyển ĐỌC là gộp hai bước có thể hỏng độc lập vào một lần deploy.
        // Bước `contract` (thôi ghi rồi drop cột) là lượt riêng.
        app(OrderPaymentIntentPointer::class)->stamp(
            (string) $order->id,
            (string) $order->organization_id,
            $paymentIntentId,
        );

        $order->refresh();
    }

    private function resolveStripeAttemptId(CustomerOrder $order, PaymentIntent $intent): ?string
    {
        $attempt = $this->orchestrationCompat->findStripeAttemptForIntent((string) $intent->id);

        return $attempt === null ? null : (string) $attempt->id;
    }

    private function finalizeStripeAttemptIfRouted(CustomerOrder $order, PaymentIntent $intent): void
    {
        $attempt = $this->orchestrationCompat->findStripeAttemptForIntent((string) $intent->id);
        if ($attempt === null) {
            return;
        }

        $this->orchestrationCompat->finalizeStripePayment($attempt->fresh() ?? $attempt, $intent);
    }

    /**
     * Issue a refund against a Stripe charge via the Refunds API (#548,
     * option a). This reverses the ACTUAL card charge — the in-app refund
     * flow calls this BEFORE writing the negative ledger row so a Stripe
     * failure aborts without a phantom "refunded" book entry.
     *
     * Money math is EXACT: `$amount` is a major-unit decimal; toMinorUnits()
     * scales it via the zero-decimal table (JPY/VND stay whole, two-decimal
     * currencies × 100) with no float drift.
     *
     * Idempotent at the Stripe layer via `$idempotencyKey`: a queue/HTTP
     * retry carrying the same key returns Stripe's SAME refund object instead
     * of charging back the card a second time.
     *
     * #815 — `$currency` must be the currency the card was ACTUALLY charged in
     * (authoritative for both post-fix and legacy intents). When null it is read
     * from the PaymentIntent itself; callers that already hold the intent pass its
     * currency to avoid the extra round-trip. NEVER the global config currency —
     * scaling a refund by the wrong currency mis-returns money by a large factor.
     */
    public function refundPayment(string $paymentIntentId, float $amount, string $idempotencyKey, ?string $currency = null): Refund
    {
        // #1081 — the refund must run in the SAME Connect scope the intent was
        // created under, or Stripe cannot find the charge.
        $refundConnection = $this->connectionForIntent($paymentIntentId);

        if ($currency === null) {
            $currency = (string) $this->gateway->retrievePaymentIntent($paymentIntentId, $refundConnection)->currency;
        }

        $minorAmount = $this->toMinorUnits($amount, strtoupper($currency));

        if ($minorAmount < 1) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }

        return $this->gateway->createRefund([
            'payment_intent' => $paymentIntentId,
            'amount' => $minorAmount,
        ], $refundConnection, [
            'idempotency_key' => 'refund_'.$idempotencyKey,
        ]);
    }

    /**
     * Auto-refund a captured-but-not-credited charge (MONEY). Shared by two
     * refuse-to-ledger cases, distinguished by `$idempotencyKey`:
     *  - `overpay_<intentId>` — the per-order overpayment guard refused the slice
     *    (a concurrent full/split payment already collected the whole balance).
     *  - `mismatch_<intentId>` — #815 currency guard refused a charge whose currency
     *    ≠ the order's priced currency.
     *
     * In both cases the customer's card was ALREADY charged, so this returns their
     * money via the Stripe Refunds API. The key is passed through to refundPayment()
     * (namespaced `refund_<key>`) so the webhook, the synchronous confirm path, and
     * any retry issue at most one refund per stranded charge — and the two cases
     * never collide.
     *
     * The refund is scaled by the INTENT's own currency (authoritative), not config.
     *
     * Guarded by the STRIPE_LIVE_REFUNDS_ENABLED kill-switch (defaults off).
     * Never throws: a Stripe hiccup must not break the webhook 200 ACK, so a
     * failure is logged at error level for the manual refund-reconciliation queue.
     */
    private function refundStrandedCharge(PaymentIntent $intent, float $chargedAmount, string $idempotencyKey, ?CustomerOrder $order = null): void
    {
        if (! (bool) config('payments.stripe_live_refunds_enabled', false)) {
            // #1206 — ERROR, not warning, and tagged. Customer money is sitting
            // at Stripe: that is a fault, not a note. DevOps alerting matches
            // ERROR-level entries by their `[...]` prefix, so this line missed
            // the alerting contract TWICE — below the level AND without a tag —
            // and STRIPE_LIVE_REFUNDS_ENABLED defaults off, making this the
            // DEFAULT path for a stranded charge rather than a rare one.
            // The kill-switch itself is untouched: this changes who hears about
            // it, not whether the refund is attempted.
            Log::error('[payments.stranded] Stranded charge refund SKIPPED — STRIPE_LIVE_REFUNDS_ENABLED is off', [
                'payment_intent_id' => $intent->id,
                'idempotency_key' => $idempotencyKey,
                'charged_amount' => $chargedAmount,
            ]);

            $this->enqueueStrandedCharge($intent, $chargedAmount, $idempotencyKey, $order, 'auto_refund_disabled');

            return;
        }

        if ($chargedAmount <= 0) {
            return;
        }

        try {
            $refund = $this->refundPayment($intent->id, $chargedAmount, $idempotencyKey, (string) $intent->currency);

            Log::info('Stranded charge auto-refunded', [
                'payment_intent_id' => $intent->id,
                'idempotency_key' => $idempotencyKey,
                'stripe_refund_id' => $refund->id ?? null,
                'charged_amount' => $chargedAmount,
            ]);
        } catch (\Throwable $e) {
            // #1206 — tagged; the level was already right.
            Log::error('[payments.stranded] Stranded charge auto-refund FAILED — manual refund required', [
                'payment_intent_id' => $intent->id,
                'idempotency_key' => $idempotencyKey,
                'charged_amount' => $chargedAmount,
                'error' => $e->getMessage(),
            ]);

            $this->enqueueStrandedCharge($intent, $chargedAmount, $idempotencyKey, $order, 'auto_refund_failed: '.$e->getMessage());
        }
    }

    /**
     * #1206 — make a stranded charge countable.
     *
     * Both branches above end with customer money still at the gateway, and
     * until now each left only a log line. The relay will NOT return the money
     * on its own — that is a human's decision, and the kill-switch exists
     * precisely so nobody automates it by accident — but the row turns "someone
     * must read the log" into a durable, attributable, countable debt that the
     * stale alert keeps raising until it is closed.
     *
     * Keyed on the INTENT, not the order: two stranded charges on one order are
     * two separate debts, and keying on the order would silently merge them and
     * drop the second amount.
     */
    private function enqueueStrandedCharge(
        PaymentIntent $intent,
        float $chargedAmount,
        string $idempotencyKey,
        ?CustomerOrder $order,
        string $reason,
    ): void {
        $organizationId = $order?->organization_id;
        if ($organizationId === null) {
            // Without a tenant the row cannot be attributed or reported on, and
            // an unattributable task is worse than none — the log line above
            // still carries the intent id for a human.
            return;
        }

        app(MoneyReconciliationQueue::class)->enqueue(
            MoneyReconciliationQueue::TYPE_STRANDED_CHARGE,
            MoneyReconciliationQueue::SUBJECT_PAYMENT_INTENT,
            MoneyReconciliationQueue::subjectIdForGatewayReference((string) $intent->id),
            [
                'payment_intent_id' => (string) $intent->id,
                'idempotency_key' => $idempotencyKey,
                'customer_order_id' => (string) $order->id,
                'charged_amount' => $chargedAmount,
                'currency_code' => strtoupper((string) $intent->currency),
                'origin' => 'auto_refund_path',
            ],
            (string) $organizationId,
            $order->branch_id === null ? null : (string) $order->branch_id,
            $reason,
        );
    }

    /**
     * Normalize the refund sublist on a `charge.refunded` webhook event into
     * ledger-ready rows. Pure — no DB writes; the caller feeds each row to
     * OrderPaymentService::syncStripeRefund() which keys idempotently on the
     * Stripe refund id.
     *
     * Handles BOTH directions of #548:
     *  - dashboard-initiated refunds (chiều B) — first time the ledger sees them
     *  - in-app refunds (chiều A) — already recorded, matched + skipped downstream
     *
     * @return list<array{payment_intent: string, stripe_refund_id: string, amount: float, currency: string}>
     */
    public function extractRefunds(Charge $charge): array
    {
        $paymentIntentId = is_string($charge->payment_intent)
            ? $charge->payment_intent
            : ($charge->payment_intent->id ?? null);

        if (! $paymentIntentId) {
            return [];
        }

        $refunds = $charge->refunds->data ?? [];
        $rows = [];

        foreach ($refunds as $refund) {
            // Only succeeded refunds actually moved money; pending/failed
            // refunds must not touch the ledger.
            if (($refund->status ?? 'succeeded') !== 'succeeded') {
                continue;
            }

            // #815 — currency from the actual refund/charge (always present on a real
            // charge.refunded event); no global-config fallback that could mis-label it.
            $currency = strtoupper((string) ($refund->currency ?? $charge->currency ?? ''));

            $rows[] = [
                'payment_intent' => $paymentIntentId,
                'stripe_refund_id' => (string) $refund->id,
                'amount' => $this->fromMinorUnits((int) $refund->amount, $currency),
                'currency' => $currency,
            ];
        }

        return $rows;
    }

    /**
     * #1123 (D2) — normalize a webhook dispute object (the inbox
     * `dispute_snapshot`) into the fields the ledger sync needs. Amount
     * converts from Stripe minor units using the dispute's own currency.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{payment_intent: string, dispute_id: string, amount: float, currency: string, status: string}|null
     */
    public function extractDisputeFromSnapshot(array $snapshot): ?array
    {
        $paymentIntent = $snapshot['payment_intent'] ?? null;
        if (is_array($paymentIntent)) {
            $paymentIntent = $paymentIntent['id'] ?? null;
        }
        $disputeId = $snapshot['id'] ?? null;

        if (! is_string($paymentIntent) || $paymentIntent === '' || ! is_string($disputeId) || $disputeId === '') {
            return null;
        }

        $currency = strtoupper((string) ($snapshot['currency'] ?? ''));

        return [
            'payment_intent' => $paymentIntent,
            'dispute_id' => $disputeId,
            'amount' => $this->fromMinorUnits((int) ($snapshot['amount'] ?? 0), $currency),
            'currency' => $currency,
            'status' => (string) ($snapshot['status'] ?? ''),
        ];
    }

    private function fromMinorUnits(int $minorAmount, string $currency): float
    {
        if (ZeroDecimalCurrency::contains($currency)) {
            return (float) $minorAmount;
        }

        return round($minorAmount / 100, 2);
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        if (ZeroDecimalCurrency::contains($currency)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }
}
