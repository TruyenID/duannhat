<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

final readonly class Money implements JsonSerializable
{
    public string $currency;

    public function __construct(
        public int $minorAmount,
        string $currency,
    ) {
        if ($minorAmount <= 0) {
            throw new InvalidArgumentException('minorAmount must be greater than zero.');
        }

        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('currency must be a three-letter ISO 4217 code.');
        }

        $this->currency = $currency;
    }

    /** @return array{minor_amount: int, currency: string} */
    public function jsonSerialize(): array
    {
        return ['minor_amount' => $this->minorAmount, 'currency' => $this->currency];
    }
}
