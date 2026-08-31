<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use App\Services\Payment\Gateway\Enums\CapabilityFact;
use App\Services\Payment\Gateway\Enums\CapabilityOperator;
use InvalidArgumentException;
use JsonSerializable;

final readonly class CapabilityPredicate implements JsonSerializable
{
    public function __construct(
        public CapabilityFact $fact,
        public CapabilityOperator $operator,
        public bool|string $expected = true,
    ) {
        if ($operator === CapabilityOperator::IsTrue && $expected !== true) {
            throw new InvalidArgumentException('An is_true predicate must expect true.');
        }

        if (is_string($expected) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/', $expected) !== 1) {
            throw new InvalidArgumentException('Capability predicate expected value is invalid.');
        }
    }

    /** @param array<string, bool|string> $facts */
    public function evaluate(array $facts): bool
    {
        if (! array_key_exists($this->fact->value, $facts)) {
            return false;
        }

        return match ($this->operator) {
            CapabilityOperator::Equals => $facts[$this->fact->value] === $this->expected,
            CapabilityOperator::IsTrue => $facts[$this->fact->value] === true,
        };
    }

    /** @return array{fact: string, operator: string, expected: bool|string} */
    public function jsonSerialize(): array
    {
        return [
            'fact' => $this->fact->value,
            'operator' => $this->operator->value,
            'expected' => $this->expected,
        ];
    }
}
