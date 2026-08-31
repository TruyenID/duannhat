<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Soft-delete an open workstation-synced draft order (idempotent). */
final readonly class SoftDeleteWorkstationOrderCommand extends MutationCommand
{
    public string $orderId;

    public function __construct(MutationContext $context, string $orderId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
    }
}
