<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ApproveOrderItemRefundCommand extends MutationCommand
{
    public string $orderId;

    public string $itemId;

    /**
     * Declared, never promoted: the constructor body re-assigns it through
     * `safeToken()`, and on a `readonly` class a promoted property is already
     * written before the body runs — the second write threw
     * `Cannot modify readonly property` on every instantiation (#2254).
     */
    public string $reason;

    /**
     * @param  float  $quantity  fractional refunds are legitimate (weight-sold
     *                           items; the request validates numeric gt:0) — an int here silently
     *                           truncated 0.5 to 0 and threw. Matches the legacy float signature.
     */
    public ?string $refundLineId;

    public function __construct(MutationContext $context, string $orderId, string $itemId, public float $quantity, string $reason, ?string $refundLineId = null)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::uuid($itemId, 'itemId');
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Refund quantity must be positive.');
        }
        $this->reason = self::safeToken($reason, 'reason', 500);
        // The workstation stamps its local refund-line UUID so a queue retry
        // resolves to the SAME appended line instead of refunding twice.
        $this->refundLineId = self::nullableUuid($refundLineId, 'refundLineId');
    }
}
