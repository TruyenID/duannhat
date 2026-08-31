<?php

namespace App\Services\Order\Commands;

use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class VoidOrderItemCommand extends MutationCommand
{
    public string $orderId;

    public string $itemId;

    public string $reason;

    public string $occurredAt;

    public function __construct(MutationContext $context, string $orderId, string $itemId, public OrderItemStatusEnum $fromStatus, string $reason, string $occurredAt)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        if ($fromStatus === OrderItemStatusEnum::Voided || $fromStatus === OrderItemStatusEnum::Served) {
            throw new \InvalidArgumentException('Served or already voided items cannot be voided through this command.');
        }
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::uuid($itemId, 'itemId');
        $this->reason = self::safeToken($reason, 'reason', 500);
        $this->occurredAt = self::isoDateTime($occurredAt, 'occurredAt');
    }
}
