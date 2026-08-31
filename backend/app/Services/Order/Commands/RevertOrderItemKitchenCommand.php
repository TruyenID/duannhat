<?php

namespace App\Services\Order\Commands;

use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class RevertOrderItemKitchenCommand extends MutationCommand
{
    public string $orderId;

    public string $itemId;

    public string $reason;

    public function __construct(MutationContext $context, string $orderId, string $itemId, public OrderItemStatusEnum $targetStatus, string $reason)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        if (! in_array($targetStatus, [OrderItemStatusEnum::Pending, OrderItemStatusEnum::Preparing], true)) {
            throw new \InvalidArgumentException('KDS reversion target must be pending or preparing.');
        } $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::uuid($itemId, 'itemId');
        $this->reason = self::safeToken($reason, 'reason', 500);
    }
}
