<?php

namespace App\Services\Payment\Settlement\Stripe;

use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Gateway\Stripe\StripeConnectScope;
use App\Services\Payment\ProviderEvent\GatewayConnectionDataFactory;
use RuntimeException;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\Payout;
use Stripe\StripeClient;

/**
 * Plan-050 M2 — production StripeSettlementClient. Thin SDK passthrough,
 * Connect-scoped per connection exactly like StripePaymentGateway's raw
 * ops; no Eloquent writes, no ledger knowledge.
 */
final class StripeSettlementApiClient implements StripeSettlementClient
{
    private ?StripeClient $client;

    public function __construct(?StripeClient $client = null)
    {
        $this->client = $client;
    }

    public function retrieveCharge(PaymentGatewayConnection $connection, string $chargeId): Charge
    {
        $scope = $this->scope($connection);

        return $scope === []
            ? $this->client()->charges->retrieve($chargeId)
            : $this->client()->charges->retrieve($chargeId, [], $scope);
    }

    public function retrieveBalanceTransaction(PaymentGatewayConnection $connection, string $balanceTransactionId): BalanceTransaction
    {
        $scope = $this->scope($connection);

        return $scope === []
            ? $this->client()->balanceTransactions->retrieve($balanceTransactionId)
            : $this->client()->balanceTransactions->retrieve($balanceTransactionId, [], $scope);
    }

    public function retrievePayout(PaymentGatewayConnection $connection, string $payoutId): Payout
    {
        $scope = $this->scope($connection);

        return $scope === []
            ? $this->client()->payouts->retrieve($payoutId)
            : $this->client()->payouts->retrieve($payoutId, [], $scope);
    }

    public function listBalanceTransactionsForPayout(PaymentGatewayConnection $connection, string $payoutId): array
    {
        $scope = $this->scope($connection);
        $transactions = [];
        $params = ['payout' => $payoutId, 'limit' => 100];

        do {
            $page = $scope === []
                ? $this->client()->balanceTransactions->all($params)
                : $this->client()->balanceTransactions->all($params, $scope);

            foreach ($page->data as $transaction) {
                $transactions[] = $transaction;
            }

            $last = end($page->data);
            $params['starting_after'] = $last !== false ? (string) $last->id : null;
        } while ($page->has_more && $params['starting_after'] !== null);

        return $transactions;
    }

    public function listWebhookEndpoints(PaymentGatewayConnection $connection): array
    {
        $scope = $this->scope($connection);
        $endpoints = [];
        $params = ['limit' => 100];

        do {
            $page = $scope === []
                ? $this->client()->webhookEndpoints->all($params)
                : $this->client()->webhookEndpoints->all($params, $scope);

            foreach ($page->data as $endpoint) {
                $endpoints[] = $endpoint;
            }

            $last = end($page->data);
            $params['starting_after'] = $last !== false ? (string) $last->id : null;
        } while ($page->has_more && $params['starting_after'] !== null);

        return $endpoints;
    }

    /**
     * @return array<string, mixed>
     */
    private function scope(PaymentGatewayConnection $connection): array
    {
        return StripeConnectScope::requestOptions(GatewayConnectionDataFactory::fromModel($connection));
    }

    private function client(): StripeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $secret = (string) config('services.stripe.secret');
        if ($secret === '') {
            throw new RuntimeException('STRIPE_SECRET is not configured.');
        }

        return $this->client = new StripeClient($secret);
    }
}
