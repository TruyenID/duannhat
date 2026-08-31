<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class StampOrderStripeIntentCommand extends MutationCommand
{
    public string $orderId;

    /** Null = CLEAR the pointer (#1125 — release an order whose async intent died). */
    public ?string $paymentIntentId;

    public function __construct(MutationContext $context, string $orderId, ?string $paymentIntentId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->paymentIntentId = $paymentIntentId === null ? null : self::safeToken($paymentIntentId, 'paymentIntentId', 255);
    }
}
