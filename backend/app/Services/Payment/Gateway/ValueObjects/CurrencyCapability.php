<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

final readonly class CurrencyCapability implements JsonSerializable
{
    public string $code;

    public function __construct(string $code, public int $minorUnit)
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException('Currency capability code must be ISO 4217.');
        }

        if ($minorUnit < 0 || $minorUnit > 3) {
            throw new InvalidArgumentException('Currency minor unit must be between zero and three.');
        }

        $this->code = $code;
    }

    /** @return array{code: string, minor_unit: int} */
    public function jsonSerialize(): array
    {
        return ['code' => $this->code, 'minor_unit' => $this->minorUnit];
    }
}
