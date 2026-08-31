<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use App\Services\Payment\Gateway\Enums\GatewayCapability;
use JsonSerializable;

final readonly class OperationCapability implements JsonSerializable
{
    public function __construct(
        public GatewayCapability $operation,
        public CapabilityRule $rule,
    ) {}

    /** @return array{operation: string, rule: CapabilityRule} */
    public function jsonSerialize(): array
    {
        return ['operation' => $this->operation->value, 'rule' => $this->rule];
    }
}
