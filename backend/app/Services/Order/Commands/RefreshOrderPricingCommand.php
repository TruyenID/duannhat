<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Recompute stored order totals from current line items (headless coupon refresh path). */
final readonly class RefreshOrderPricingCommand extends MutationCommand
{
    public string $orderId;

    public function __construct(MutationContext $context, string $orderId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
    }
}
