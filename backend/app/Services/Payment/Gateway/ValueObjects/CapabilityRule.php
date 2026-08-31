<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use App\Services\Payment\Gateway\Enums\CapabilitySupport;
use InvalidArgumentException;
use JsonSerializable;

final readonly class CapabilityRule implements JsonSerializable
{
    public function __construct(
        public CapabilitySupport $support,
        public ?CapabilityCondition $condition = null,
    ) {
        if ($support === CapabilitySupport::Conditional && $condition === null) {
            throw new InvalidArgumentException('A conditional capability requires a machine-evaluable condition.');
        }

        if ($support !== CapabilitySupport::Conditional && $condition !== null) {
            throw new InvalidArgumentException('Only a conditional capability may define a condition.');
        }

    }

    /** @return array{support: string, when?: CapabilityCondition} */
    public function jsonSerialize(): array
    {
        return array_filter([
            'support' => $this->support->value,
            'when' => $this->condition,
        ], fn (mixed $value): bool => $value !== null);
    }
}
