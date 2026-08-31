<?php

namespace App\Services\Payment\Secret\ValueObjects;

use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\ValueObjects\GatewayValue;

final readonly class GatewaySecretAccessContext extends GatewayValue
{
    public string $organizationId;

    public string $connectionId;

    public string $actorId;

    public string $correlationId;

    public function __construct(
        string $organizationId,
        string $connectionId,
        public PaymentGatewayProviderCodeEnum $provider,
        public PaymentGatewayEnvironmentEnum $environment,
        string $actorId,
        string $correlationId,
    ) {
        $this->organizationId = self::uuid($organizationId, 'organizationId');
        $this->connectionId = self::uuid($connectionId, 'connectionId');
        $this->actorId = self::requestKey($actorId, 'actorId');
        $this->correlationId = self::requestKey($correlationId, 'correlationId');
    }
}
