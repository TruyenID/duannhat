<?php

namespace App\Services\Customer\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;

/** Tri-state PATCH field: omitted, explicitly cleared, or replaced. */
final readonly class OptionalProfileField implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    private function __construct(public bool $provided, public ?string $value) {}

    public static function omitted(): self
    {
        return new self(false, null);
    }

    public static function clear(): self
    {
        return new self(true, null);
    }

    public static function replace(string $value): self
    {
        return new self(true, $value);
    }

    public function jsonSerialize(): array
    {
        return ['provided' => $this->provided, 'value' => $this->value];
    }
}
