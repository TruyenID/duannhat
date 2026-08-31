<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Lightweight Confirmed/Open/Dining → Checkout before device payment (kiosk/workstation). */
final readonly class PromoteOrderForPaymentCommand extends MutationCommand
{
    public string $orderId;

    public function __construct(MutationContext $context, string $orderId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
    }
}
