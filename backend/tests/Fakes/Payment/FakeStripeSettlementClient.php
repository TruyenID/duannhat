<?php

namespace Tests\Fakes\Payment;

use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Settlement\Stripe\StripeSettlementClient;
use RuntimeException;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\Payout;
use Stripe\WebhookEndpoint;

/**
 * Plan-050 — in-memory StripeSettlementClient for settlement tests.
 *
 * Register canned objects (plain arrays in Stripe wire shape), then bind the
 * fake over the StripeSettlementClient interface. No settlement test ever
 * performs real HTTP.
 */
final class FakeStripeSettlementClient implements StripeSettlementClient
{
    /** @var array<string, array<string, mixed>> */
    private array $charges = [];

    /** @var array<string, array<string, mixed>> */
    private array $balanceTransactions = [];

    /** @var array<string, array<string, mixed>> */
    private array $payouts = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $payoutListings = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $webhookEndpoints = [];

    /** @var array<string, string> */
    private array $webhookEndpointFailures = [];

    /** @var array<string, int> */
    public array $calls = [];

    /**
     * @param  array<string, mixed>  $charge
     */
    public function withCharge(array $charge): self
    {
        $this->charges[(string) $charge['id']] = $charge + ['object' => 'charge'];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function withBalanceTransaction(array $transaction): self
    {
        $this->balanceTransactions[(string) $transaction['id']] = $transaction + ['object' => 'balance_transaction'];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $payout
     */
    public function withPayout(array $payout): self
    {
        $this->payouts[(string) $payout['id']] = $payout + ['object' => 'payout'];

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     */
    public function withPayoutListing(string $payoutId, array $transactions): self
    {
        $this->payoutListings[$payoutId] = $transactions;

        return $this;
    }

    /**
     * T2.4 (#1978) — webhook endpoints are keyed by CONNECTION because the
     * audit compares one Stripe account scope at a time; an unregistered
     * connection lists nothing (every required event missing).
     *
     * @param  list<array<string, mixed>>  $endpoints
     */
    public function withWebhookEndpoints(string $connectionId, array $endpoints): self
    {
        $this->webhookEndpoints[$connectionId] = $endpoints;

        return $this;
    }

    /** Make the endpoint listing blow up for one connection (gateway down). */
    public function failWebhookEndpoints(string $connectionId, string $message): self
    {
        $this->webhookEndpointFailures[$connectionId] = $message;

        return $this;
    }

    public function retrieveCharge(PaymentGatewayConnection $connection, string $chargeId): Charge
    {
        $this->calls['retrieveCharge'] = ($this->calls['retrieveCharge'] ?? 0) + 1;

        return Charge::constructFrom($this->charges[$chargeId]
            ?? throw new RuntimeException("Fake has no charge {$chargeId}."));
    }

    public function retrieveBalanceTransaction(PaymentGatewayConnection $connection, string $balanceTransactionId): BalanceTransaction
    {
        $this->calls['retrieveBalanceTransaction'] = ($this->calls['retrieveBalanceTransaction'] ?? 0) + 1;

        return BalanceTransaction::constructFrom($this->balanceTransactions[$balanceTransactionId]
            ?? throw new RuntimeException("Fake has no balance transaction {$balanceTransactionId}."));
    }

    public function retrievePayout(PaymentGatewayConnection $connection, string $payoutId): Payout
    {
        $this->calls['retrievePayout'] = ($this->calls['retrievePayout'] ?? 0) + 1;

        return Payout::constructFrom($this->payouts[$payoutId]
            ?? throw new RuntimeException("Fake has no payout {$payoutId}."));
    }

    public function listBalanceTransactionsForPayout(PaymentGatewayConnection $connection, string $payoutId): array
    {
        $this->calls['listBalanceTransactionsForPayout'] = ($this->calls['listBalanceTransactionsForPayout'] ?? 0) + 1;

        return array_map(
            static fn (array $transaction): BalanceTransaction => BalanceTransaction::constructFrom(
                $transaction + ['object' => 'balance_transaction'],
            ),
            $this->payoutListings[$payoutId] ?? [],
        );
    }

    public function listWebhookEndpoints(PaymentGatewayConnection $connection): array
    {
        $this->calls['listWebhookEndpoints'] = ($this->calls['listWebhookEndpoints'] ?? 0) + 1;

        $connectionId = (string) $connection->id;

        if (isset($this->webhookEndpointFailures[$connectionId])) {
            throw new RuntimeException($this->webhookEndpointFailures[$connectionId]);
        }

        return array_map(
            static fn (array $endpoint): WebhookEndpoint => WebhookEndpoint::constructFrom(
                $endpoint + ['object' => 'webhook_endpoint'],
            ),
            $this->webhookEndpoints[$connectionId] ?? [],
        );
    }
}
