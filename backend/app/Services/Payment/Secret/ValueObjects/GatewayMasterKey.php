<?php

namespace App\Services\Payment\Secret\ValueObjects;

use App\Services\Payment\Gateway\ValueObjects\EphemeralSecret;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

final class GatewayMasterKey
{
    private EphemeralSecret $material;

    public function __construct(
        public readonly string $keyId,
        #[SensitiveParameter] string $bytes,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', $keyId) !== 1) {
            throw new InvalidArgumentException('Gateway master key ID is invalid.');
        }

        if (strlen($bytes) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('Gateway master key must contain exactly 32 bytes.');
        }

        $this->material = new EphemeralSecret($bytes);
    }

    public function bytes(): string
    {
        return $this->material->reveal();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Gateway master keys cannot be serialized.');
    }

    /** @return array{key_id: string} */
    public function __debugInfo(): array
    {
        return ['key_id' => $this->keyId];
    }
}
