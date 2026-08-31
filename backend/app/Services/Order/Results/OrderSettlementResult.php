<?php

namespace App\Services\Order\Results;

use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class OrderSettlementResult
{
    public string $orderId;

    public string $currencyCode;

    public function __construct(string $orderId, public int $version, public bool $settled, public int $settledAmountMinor, string $currencyCode)
    {
        $this->orderId = MutationCommand::uuid($orderId, 'orderId');
        $this->currencyCode = strtoupper(trim($currencyCode));

        if ($version < 1 || $settledAmountMinor < 0 || preg_match('/^[A-Z]{3}$/', $this->currencyCode) !== 1) {
            throw new InvalidArgumentException('Order settlement outcome is invalid.');
        }
    }
}
