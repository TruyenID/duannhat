<?php

namespace App\Services\Order\Commands;

use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class AdvanceOrderItemKitchenCommand extends MutationCommand
{
    public string $orderId;

    public string $itemId;

    public string $occurredAt;

    public function __construct(MutationContext $context, string $orderId, string $itemId, public OrderItemStatusEnum $fromStatus, public OrderItemStatusEnum $status, string $occurredAt)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::uuid($itemId, 'itemId');
        $legal = [
            OrderItemStatusEnum::Pending->value => OrderItemStatusEnum::Preparing,
            OrderItemStatusEnum::Preparing->value => OrderItemStatusEnum::Ready,
            OrderItemStatusEnum::Ready->value => OrderItemStatusEnum::Served,
        ];
        if (($legal[$fromStatus->value] ?? null) !== $status) {
            throw new \InvalidArgumentException('Kitchen advancement must be exactly one legal forward transition.');
        }
        $this->occurredAt = self::isoDateTime($occurredAt, 'occurredAt');
    }
}
