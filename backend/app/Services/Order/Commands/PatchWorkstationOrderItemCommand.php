<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Atomically replace the mutable selection/header fields of a workstation line. */
final readonly class PatchWorkstationOrderItemCommand extends MutationCommand
{
    public string $orderId;

    public string $itemId;

    /** @var array<string, mixed> */
    public array $patch;

    /**
     * @param  array<string, mixed>  $patch
     */
    public function __construct(MutationContext $context, string $orderId, string $itemId, array $patch)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::uuid($itemId, 'itemId');
        // #1148 — SKU is immutable on a line (void + re-add only); the
        // selection keys are no longer patchable.
        $this->patch = array_intersect_key($patch, array_flip(['quantity', 'note', 'toppings']));
    }
}
