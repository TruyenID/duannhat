<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class VoidOrderCommand extends MutationCommand
{
    public string $orderId;

    public string $reason;

    /**
     * #1283 — optional VoidReason master id. Cloud resolves it (brand +
     * active) and it drives the plan-051 stock-compensation truth table, the
     * same way the item-level void already does. Nullable so every existing
     * caller keeps working: an absent reason means the conservative branch —
     * a deducted line is never restocked on an unknown reason.
     */
    public ?string $reasonId;

    public function __construct(MutationContext $context, string $orderId, string $reason, ?string $reasonId = null)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->reason = self::safeToken($reason, 'reason', 500);
        $this->reasonId = $reasonId === null ? null : self::uuid($reasonId, 'reasonId');
    }
}
