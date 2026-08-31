<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** KDS / workstation kitchen status bump with idempotency key passthrough. */
final readonly class BumpKitchenOrderItemStatusCommand extends MutationCommand
{
    public string $orderId;

    public string $itemId;

    public string $status;

    public string $idempotencyKey;

    public function __construct(
        MutationContext $context,
        string $orderId,
        string $itemId,
        string $status,
        string $idempotencyKey,
    ) {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::uuid($itemId, 'itemId');
        if (! in_array($status, ['pending', 'preparing', 'ready', 'served'], true)) {
            throw new \InvalidArgumentException('status must be a kitchen lifecycle value.');
        }
        $this->status = $status;
        $this->idempotencyKey = self::safeToken($idempotencyKey, 'idempotencyKey', 200);
    }
}
