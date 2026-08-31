<?php

namespace App\Services\Order\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;

final readonly class OrderPricingEvidence implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public function __construct(
        public int $subtotalMinor,
        public int $discountMinor,
        public int $serviceChargeMinor,
        public int $taxMinor,
        public int $totalMinor,
        public bool $taxIncluded,
        public string $taxRoundingMode,
        public ?int $taxRoundingDecimals,
    ) {
        if (min($subtotalMinor, $discountMinor, $serviceChargeMinor, $taxMinor, $totalMinor) < 0) {
            throw new \InvalidArgumentException('Order pricing evidence cannot be negative.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
