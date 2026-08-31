<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class RemoveOrderItemCommand extends MutationCommand
{
    public string $orderId;

    public string $itemId;

    public string $reason;

    public function __construct(MutationContext $context, string $orderId, string $itemId, string $reason)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::uuid($itemId, 'itemId');
        $this->reason = self::safeToken($reason, 'reason', 500);
    }
}
