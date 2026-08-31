<?php

namespace App\Services\Payment\Secret\ValueObjects;

use App\Services\Payment\Secret\Enums\GatewaySecretPurpose;
use DateTimeImmutable;

final readonly class GatewaySecretRotationResult
{
    public function __construct(
        public GatewaySecretPurpose $purpose,
        public int $version,
        public string $fingerprint,
        public string $keyId,
        public ?DateTimeImmutable $overlapUntil,
    ) {}
}
