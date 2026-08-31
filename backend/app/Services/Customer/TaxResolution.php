<?php

namespace App\Services\Customer;

final readonly class TaxResolution
{
    public function __construct(
        /** Resolved tax type id, or null when nothing resolved (legacy fallback). */
        public ?string $taxTypeId,
        public float $rate,
    ) {}
}
