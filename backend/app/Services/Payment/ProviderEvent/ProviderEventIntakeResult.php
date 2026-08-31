<?php

namespace App\Services\Payment\ProviderEvent;

final readonly class ProviderEventIntakeResult
{
    public function __construct(
        public bool $accepted,
        public bool $deduplicated,
        public ?string $providerEventRecordId,
    ) {}
}
