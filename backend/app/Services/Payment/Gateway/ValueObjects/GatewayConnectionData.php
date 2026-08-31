<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use JsonSerializable;

/**
 * Non-secret connection identity passed across the gateway boundary.
 * Credentials are deliberately absent and are resolved server-side at call time.
 */
final readonly class GatewayConnectionData extends GatewayValue implements JsonSerializable
{
    public string $connectionId;

    public string $merchantAccountReference;

    public function __construct(
        string $connectionId,
        public PaymentGatewayProviderCodeEnum $provider,
        public PaymentGatewayEnvironmentEnum $environment,
        string $merchantAccountReference,
        public int $configurationVersion,
    ) {
        $this->connectionId = self::uuid($connectionId, 'connectionId');
        $this->merchantAccountReference = self::nonEmpty($merchantAccountReference, 'merchantAccountReference');

        if ($configurationVersion < 1) {
            throw new \InvalidArgumentException('configurationVersion must be at least one.');
        }
    }

    /** @return array<string, int|string> */
    public function jsonSerialize(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'provider' => $this->provider->value,
            'environment' => $this->environment->value,
            'merchant_account_reference' => $this->merchantAccountReference,
            'configuration_version' => $this->configurationVersion,
        ];
    }
}
