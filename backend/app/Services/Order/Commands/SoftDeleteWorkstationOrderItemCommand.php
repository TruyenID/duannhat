<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Soft-delete a workstation-synced order line (idempotent). */
final readonly class SoftDeleteWorkstationOrderItemCommand extends MutationCommand
{
    public string $orderId;

    public string $itemId;

    public function __construct(MutationContext $context, string $orderId, string $itemId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::uuid($itemId, 'itemId');
    }
}
