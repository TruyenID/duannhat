<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use JsonSerializable;

final readonly class ProviderObjectReference extends GatewayValue implements JsonSerializable
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = self::nonEmpty($value, 'providerObjectReference');
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
