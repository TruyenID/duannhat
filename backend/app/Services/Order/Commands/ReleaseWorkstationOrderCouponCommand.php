<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Clear coupon fields on a workstation-synced order (idempotent). */
final readonly class ReleaseWorkstationOrderCouponCommand extends MutationCommand
{
    public string $orderId;

    public function __construct(MutationContext $context, string $orderId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
    }
}
