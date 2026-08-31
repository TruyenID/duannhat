<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class UnmergeOrderTableCommand extends MutationCommand
{
    public string $orderId;

    public string $tableId;

    public function __construct(MutationContext $context, string $orderId, string $tableId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->tableId = self::uuid($tableId, 'tableId');
    }
}
