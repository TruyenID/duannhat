<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Workstation checkout replay — promotes to checkout without full POS repricing. */
final readonly class CheckoutWorkstationOrderCommand extends MutationCommand
{
    public string $orderId;

    public ?float $discountAmount;

    public function __construct(MutationContext $context, string $orderId, ?float $discountAmount = null)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        if ($discountAmount !== null && $discountAmount < 0) {
            throw new \InvalidArgumentException('discountAmount cannot be negative.');
        }
        $this->discountAmount = $discountAmount;
    }
}
