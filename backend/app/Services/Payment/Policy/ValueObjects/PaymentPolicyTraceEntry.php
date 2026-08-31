<?php

namespace App\Services\Payment\Policy\ValueObjects;

use App\Services\Payment\Policy\Enums\PolicyDecision;
use App\Services\Payment\Policy\Enums\PolicyLayer;
use App\Services\Payment\Policy\Enums\PolicyReasonCode;
use JsonSerializable;

final readonly class PaymentPolicyTraceEntry implements JsonSerializable
{
    public function __construct(
        public PolicyLayer $layer,
        public PolicyDecision $decision,
        public PolicyReasonCode $reason,
    ) {}

    /** @return array{layer: string, decision: string, reason: string} */
    public function jsonSerialize(): array
    {
        return [
            'layer' => $this->layer->value,
            'decision' => $this->decision->value,
            'reason' => $this->reason->value,
        ];
    }
}
