<?php

namespace App\Services\Payment\Secret\ValueObjects;

use App\Services\Payment\Gateway\ValueObjects\EphemeralSecret;
use App\Services\Payment\Secret\Enums\GatewaySecretPurpose;
use LogicException;
use SensitiveParameter;

final class ResolvedGatewaySecret
{
    private EphemeralSecret $material;

    public function __construct(
        public readonly GatewaySecretPurpose $purpose,
        public readonly int $version,
        public readonly string $fingerprint,
        #[SensitiveParameter] string $plaintext,
    ) {
        $this->material = new EphemeralSecret($plaintext);
    }

    public function use(callable $consumer): mixed
    {
        return $consumer($this->material->reveal());
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Resolved gateway secrets cannot be serialized.');
    }

    /** @return array{purpose: string, version: int, fingerprint: string} */
    public function __debugInfo(): array
    {
        return [
            'purpose' => $this->purpose->value,
            'version' => $this->version,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
