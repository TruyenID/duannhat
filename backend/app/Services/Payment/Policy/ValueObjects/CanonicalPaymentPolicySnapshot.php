<?php

namespace App\Services\Payment\Policy\ValueObjects;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;

final readonly class CanonicalPaymentPolicySnapshot implements JsonSerializable
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public array $payload,
        public string $canonicalJson,
        public string $hash,
        public int $effectiveOptionCount,
    ) {
        try {
            $decoded = json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Canonical payment policy snapshot JSON is invalid.', previous: $exception);
        }

        $options = $payload['options'] ?? null;
        $calculatedEffectiveCount = is_array($options)
            ? count(array_filter(
                $options,
                static fn (mixed $option): bool => is_array($option) && ($option['effective'] ?? null) === true,
            ))
            : -1;

        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1
            || ! hash_equals($hash, hash('sha256', $canonicalJson))
            || $decoded !== $payload
            || $effectiveOptionCount < 0
            || $effectiveOptionCount !== $calculatedEffectiveCount) {
            throw new InvalidArgumentException('Canonical payment policy snapshot metadata is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->payload;
    }

    public function verifies(): bool
    {
        return hash_equals($this->hash, hash('sha256', $this->canonicalJson));
    }
}
