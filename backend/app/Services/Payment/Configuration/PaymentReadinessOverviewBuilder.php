<?php

namespace App\Services\Payment\Configuration;

use App\Models\PaymentGatewayConnection;
use App\Omnify\Enums\PaymentConnectionHealthEnum;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Payment\Configuration\Exceptions\PaymentConfigurationException;
use BackedEnum;

/**
 * The brand payment "Overview" tab, computed from real rows.
 *
 * This screen used to have no endpoint at all: `GET /hq/{brand}/payment-readiness`
 * returned 404, the admin service swallowed 404 as "backend not ready yet" and
 * rendered a hard-coded fixture — 1/2 connections, 3/5 shops, 4/6 options, plus
 * an invented blocker naming a connection id that does not exist in this brand.
 * Nothing on screen said any of it was fake, so the tab read as live data for a
 * brand that actually had 2 connections and 11 shops (#F1).
 *
 * Every number here is therefore derived from the same queries the other three
 * tabs render, so Overview cannot disagree with the tab it links to.
 */
final class PaymentReadinessOverviewBuilder
{
    public function __construct(
        private readonly PaymentGatewayConfigurationService $configuration,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $organizationId, string $brandId): array
    {
        $connections = $this->configuration
            ->listConnections($organizationId, $brandId, ['per_page' => 100])
            ->getCollection();

        $connectionsReady = $connections
            ->filter(fn (PaymentGatewayConnection $connection): bool => $this->isReady($connection))
            ->count();

        $coverage = $this->configuration->listCoverage($organizationId, $brandId);
        $shopsReady = $coverage->filter(static fn (array $row): bool => (bool) $row['connection_ready'])->count();

        $blockers = [];

        // Option policy needs the headquarters branch. If the brand has none the
        // policy tab is genuinely unusable, and that is a blocker to SHOW, not a
        // 500 to hide behind — Overview is the screen an operator opens first.
        try {
            $policies = $this->configuration->listOptionPolicies($organizationId, $brandId);
            $optionsTotal = $policies->count();
            $optionsEnabled = $policies
                ->filter(static fn (array $row): bool => $row['preference'] === PaymentPolicyPreferenceEnum::Enabled)
                ->count();
        } catch (PaymentConfigurationException $exception) {
            $optionsTotal = 0;
            $optionsEnabled = 0;
            $blockers[] = [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'href' => 'methods',
            ];
        }

        foreach ($connections as $connection) {
            if ($this->isReady($connection)) {
                continue;
            }

            $blockers[] = [
                'code' => 'GATEWAY_SETUP_REQUIRED',
                'message' => $this->connectionBlockerMessage($connection),
                'href' => 'gateways/'.$connection->id,
            ];
        }

        if ($connections->isEmpty()) {
            $blockers[] = [
                'code' => 'NO_GATEWAY_CONNECTION',
                'message' => 'This brand has no payment gateway connection yet.',
                'href' => 'gateways/new',
            ];
        }

        $shopsTotal = $coverage->count();
        if ($shopsTotal > 0 && $shopsReady < $shopsTotal) {
            $blockers[] = [
                'code' => 'PAYMENT_CONNECTION_REQUIRED',
                'message' => ($shopsTotal - $shopsReady).' shop(s) have no ready payment connection.',
                'href' => 'shops',
            ];
        }

        return [
            'overall_status' => $this->overallStatus($connections->count(), $connectionsReady, $blockers),
            'connections_ready' => $connectionsReady,
            'connections_total' => $connections->count(),
            'shops_ready' => $shopsReady,
            'shops_total' => $shopsTotal,
            'options_enabled' => $optionsEnabled,
            'options_total' => $optionsTotal,
            'blockers' => array_values($blockers),
        ];
    }

    private function isReady(PaymentGatewayConnection $connection): bool
    {
        return (bool) $connection->is_active
            && $this->scalar($connection->health) === PaymentConnectionHealthEnum::Ready->value;
    }

    private function connectionBlockerMessage(PaymentGatewayConnection $connection): string
    {
        $provider = $this->scalar($connection->provider?->code) ?? 'gateway';
        $reason = $this->scalar($connection->health_reason_code)
            ?? $this->scalar($connection->health)
            ?? 'not_ready';

        return sprintf('%s connection is not ready (%s).', $provider, $reason);
    }

    /**
     * @param  list<array<string, mixed>>  $blockers
     */
    private function overallStatus(int $connectionsTotal, int $connectionsReady, array $blockers): string
    {
        if ($connectionsTotal === 0) {
            return 'setup_required';
        }

        if ($blockers === [] && $connectionsReady === $connectionsTotal) {
            return 'ready';
        }

        return 'action_required';
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value === null ? null : (string) $value;
    }
}
