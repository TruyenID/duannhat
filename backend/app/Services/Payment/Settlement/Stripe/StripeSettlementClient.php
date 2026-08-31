<?php

namespace App\Services\Payment\Settlement\Stripe;

use App\Models\PaymentGatewayConnection;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\Payout;
use Stripe\WebhookEndpoint;

/**
 * Plan-050 M2 — the ONLY Stripe API surface the settlement layer touches.
 *
 * A dedicated narrow port (instead of widening StripePaymentGateway) so
 * tests bind an in-memory fake and no settlement test ever performs real
 * HTTP. Every call is scoped to the owning connection (Connect account
 * scope included) — a merchant's balance data can never be read off the
 * wrong account.
 */
interface StripeSettlementClient
{
    public function retrieveCharge(PaymentGatewayConnection $connection, string $chargeId): Charge;

    public function retrieveBalanceTransaction(PaymentGatewayConnection $connection, string $balanceTransactionId): BalanceTransaction;

    public function retrievePayout(PaymentGatewayConnection $connection, string $payoutId): Payout;

    /**
     * All balance transactions attached to a payout (auto-paginated),
     * including the payout's own `type=payout` transaction.
     *
     * @return list<BalanceTransaction>
     */
    public function listBalanceTransactionsForPayout(PaymentGatewayConnection $connection, string $payoutId): array;

    /**
     * T2.4 (#1978) — every webhook endpoint registered in the connection's
     * Stripe account scope (auto-paginated), so the audit can compare what
     * the gateway is subscribed to against what the settlement layer needs.
     *
     * SCOPE CAVEAT: like every other call on this port, this reads the
     * connection's OWN account. A platform-level Connect endpoint
     * (`connect: true`, delivering events for all connected accounts) is not
     * visible from a connected-account scope, and the retrieved
     * WebhookEndpoint object exposes no field saying whether a platform
     * endpoint is a Connect endpoint — so the two cannot be merged without
     * guessing. Audit the platform connection separately.
     *
     * @return list<WebhookEndpoint>
     */
    public function listWebhookEndpoints(PaymentGatewayConnection $connection): array;
}
