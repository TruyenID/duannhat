<?php

namespace App\Services\Order\Results;

use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class OrderCreatedResult
{
    public string $orderId;

    public function __construct(string $orderId, public int $version, public int $itemCount)
    {
        if ($version < 1 || $itemCount < 0) {
            throw new InvalidArgumentException('Order creation outcome is invalid.');
        }

        $this->orderId = MutationCommand::uuid($orderId, 'orderId');
    }
}
