<?php

namespace App\Services\Payment\Policy\ValueObjects;

use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Services\Payment\Policy\Enums\PolicyReasonCode;
use JsonSerializable;

final readonly class EffectivePaymentOption implements JsonSerializable
{
    /** @var list<PaymentPolicyTraceEntry> */
    public array $trace;

    /** @param list<PaymentPolicyTraceEntry> $trace */
    public function __construct(
        public string $optionId,
        public string $correlationId,
        public bool $effective,
        public PolicyReasonCode $reason,
        public ?string $connectionId,
        public ?string $connectionOptionId,
        public ?string $shopOptionId,
        public ?PaymentConnectionOwnerScopeEnum $ownerScope,
        public ?string $operatorOrgUnitId,
        public ?string $ownershipRevision,
        array $trace,
    ) {
        $this->trace = array_values($trace);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'option_id' => $this->optionId,
            'correlation_id' => $this->correlationId,
            'effective' => $this->effective,
            'reason' => $this->reason->value,
            'error_code' => $this->reason->publicErrorCode(),
            'connection_id' => $this->connectionId,
            'connection_option_id' => $this->connectionOptionId,
            'shop_option_id' => $this->shopOptionId,
            'owner_scope' => $this->ownerScope?->value,
            'operator_org_unit_id' => $this->operatorOrgUnitId,
            'ownership_revision' => $this->ownershipRevision,
            'trace' => array_map(
                static fn (PaymentPolicyTraceEntry $entry): array => $entry->jsonSerialize(),
                $this->trace,
            ),
        ];
    }
}
