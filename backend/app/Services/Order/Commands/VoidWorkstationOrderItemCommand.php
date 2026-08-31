<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Void a workstation-synced order line without full kitchen void rules. */
final readonly class VoidWorkstationOrderItemCommand extends MutationCommand
{
    public string $orderId;

    public string $itemId;

    public string $reason;

    /** plan-051 — optional VoidReason master id (new workstation builds). */
    public ?string $voidReasonId;

    public function __construct(MutationContext $context, string $orderId, string $itemId, string $reason, ?string $voidReasonId = null)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::uuid($itemId, 'itemId');
        $this->reason = self::safeToken($reason, 'reason', 2000);
        $this->voidReasonId = self::nullableUuid($voidReasonId, 'voidReasonId');
    }
}
