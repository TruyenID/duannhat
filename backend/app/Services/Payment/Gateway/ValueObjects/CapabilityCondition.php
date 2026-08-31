<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

/** Closed AND predicate tree; unknown or missing facts always evaluate false. */
final readonly class CapabilityCondition implements JsonSerializable
{
    /** @var non-empty-list<CapabilityPredicate> */
    public array $allOf;

    /** @param list<CapabilityPredicate> $allOf */
    public function __construct(array $allOf)
    {
        if ($allOf === []) {
            throw new InvalidArgumentException('Capability condition requires at least one predicate.');
        }

        foreach ($allOf as $predicate) {
            if (! $predicate instanceof CapabilityPredicate) {
                throw new InvalidArgumentException('Capability condition contains an invalid predicate.');
            }
        }

        $this->allOf = array_values($allOf);
    }

    /** @param array<string, bool|string> $facts */
    public function evaluate(array $facts): bool
    {
        foreach ($this->allOf as $predicate) {
            if (! $predicate->evaluate($facts)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{all_of: non-empty-list<CapabilityPredicate>} */
    public function jsonSerialize(): array
    {
        return ['all_of' => $this->allOf];
    }
}
