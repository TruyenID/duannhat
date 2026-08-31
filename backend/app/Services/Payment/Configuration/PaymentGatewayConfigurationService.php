<?php

namespace App\Services\Payment\Configuration;

use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayOption;
use App\Models\ShopPaymentOption;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Payment\Configuration\Exceptions\PaymentConfigurationException;
use App\Services\Payment\Configuration\Internal\EloquentPaymentGatewayConfigurationPersistence;
use App\Services\Payment\Configuration\Support\SensitivePaymentDataGuard;
use App\Services\Payment\Gateway\ValueObjects\EphemeralSecret;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class PaymentGatewayConfigurationService
{
    public function __construct(
        private readonly EloquentPaymentGatewayConfigurationPersistence $persistence,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PaymentGatewayConnection>
     */
    public function listConnections(string $organizationId, string $brandId, array $filters = []): LengthAwarePaginator
    {
        return $this->persistence->listHqConnections($organizationId, $brandId, $filters);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{connection: PaymentGatewayConnection, created: bool}
     */
    public function createConnection(
        string $organizationId,
        string $brandId,
        array $payload,
        string $correlationId,
    ): array {
        SensitivePaymentDataGuard::rejectIfPresent($payload, $correlationId);

        return $this->persistence->createHqConnection($organizationId, $brandId, $payload);
    }

    public function showConnection(string $organizationId, string $brandId, string $connectionId): PaymentGatewayConnection
    {
        return $this->persistence->findHqConnection($organizationId, $brandId, $connectionId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateConnection(
        string $organizationId,
        string $brandId,
        string $connectionId,
        array $payload,
        string $correlationId,
    ): PaymentGatewayConnection {
        SensitivePaymentDataGuard::rejectIfPresent($payload, $correlationId);

        $connection = $this->persistence->findHqConnection($organizationId, $brandId, $connectionId);

        return $this->persistence->updateHqConnection($connection, $payload);
    }

    public function validateConnection(
        string $organizationId,
        string $brandId,
        string $connectionId,
        string $actorId,
        string $correlationId,
    ): PaymentGatewayConnection {
        $connection = $this->persistence->findHqConnection($organizationId, $brandId, $connectionId);
        $secretContext = $this->persistence->secretContext($connection, $actorId, $correlationId);

        return $this->persistence->validateConnection($connection, $secretContext, $correlationId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{fingerprint: string, secret_version: string|null, connection: PaymentGatewayConnection}
     */
    public function rotateConnectionSecret(
        string $organizationId,
        string $brandId,
        string $connectionId,
        array $payload,
        string $actorId,
        string $correlationId,
    ): array {
        SensitivePaymentDataGuard::rejectIfPresent($payload, $correlationId);

        $connection = $this->persistence->findHqConnection($organizationId, $brandId, $connectionId);
        $secretContext = $this->persistence->secretContext($connection, $actorId, $correlationId);
        $apiSecret = $payload['api_secret'] ?? null;

        if (! is_string($apiSecret) || trim($apiSecret) === '') {
            throw new PaymentConfigurationException(
                'An API secret is required to rotate credentials.',
                'PAYMENT_CONNECTION_REQUIRED',
                422,
                false,
                'configure_gateway',
                [],
                $correlationId,
            );
        }

        $rotation = $this->persistence->rotateApiSecret(
            $connection,
            $secretContext,
            new EphemeralSecret($apiSecret),
            $correlationId,
        );

        return [
            ...$rotation,
            'connection' => $this->persistence->findHqConnection($organizationId, $brandId, $connectionId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnectImpact(string $organizationId, string $brandId, string $connectionId): array
    {
        $connection = $this->persistence->findHqConnection($organizationId, $brandId, $connectionId);

        return $this->persistence->disconnectImpact($connection);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function disconnectConnection(
        string $organizationId,
        string $brandId,
        string $connectionId,
        array $payload,
        string $correlationId,
    ): void {
        $connection = $this->persistence->findHqConnection($organizationId, $brandId, $connectionId);
        $impact = $this->persistence->disconnectImpact($connection);
        $confirmed = (bool) ($payload['confirm'] ?? false);

        if (! $confirmed) {
            throw new PaymentConfigurationException(
                'Disconnect requires explicit impact confirmation.',
                'PAYMENT_GATEWAY_DISCONNECT_REQUIRES_CONFIRMATION',
                409,
                false,
                'review_disconnect_impact',
                ['impact' => $impact],
                $correlationId,
            );
        }

        if ($impact['shop_count'] > 0 && ! ($payload['acknowledge_shop_impact'] ?? false)) {
            throw new PaymentConfigurationException(
                'Disconnect is blocked until shop impact is acknowledged.',
                'PAYMENT_GATEWAY_DISCONNECT_BLOCKED',
                409,
                false,
                'review_disconnect_impact',
                ['impact' => $impact],
                $correlationId,
            );
        }

        $this->persistence->disconnectConnection($connection);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listOptionPolicies(string $organizationId, string $brandId): Collection
    {
        $policyBranch = $this->persistence->resolvePolicyBranch($organizationId, $brandId);

        return $this->persistence->listHqOptionPolicies($organizationId, $brandId, $policyBranch);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateOptionPolicy(
        string $organizationId,
        string $brandId,
        string $optionId,
        array $payload,
        string $correlationId,
    ): ShopPaymentOption {
        SensitivePaymentDataGuard::rejectIfPresent($payload, $correlationId);

        $policyBranch = $this->persistence->resolvePolicyBranch($organizationId, $brandId);
        $option = PaymentGatewayOption::query()->findOrFail($optionId);
        $preference = PaymentPolicyPreferenceEnum::from($payload['preference']);

        return $this->persistence->upsertHqOptionPolicy(
            $organizationId,
            $brandId,
            $policyBranch,
            $option,
            $preference,
            isset($payload['change_reason']) ? (string) $payload['change_reason'] : null,
            isset($payload['version']) ? (int) $payload['version'] : null,
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listCoverage(string $organizationId, string $brandId): Collection
    {
        return $this->persistence->listCoverage($organizationId, $brandId);
    }

    public function correlationId(): string
    {
        $header = request()->header('X-Correlation-Id');

        return is_string($header) && $header !== '' ? $header : (string) Str::uuid();
    }
}
