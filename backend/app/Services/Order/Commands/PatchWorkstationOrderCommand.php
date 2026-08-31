<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Idempotent workstation header patch (guest_count, note, customer_id, order_type, table_id). */
final readonly class PatchWorkstationOrderCommand extends MutationCommand
{
    public string $orderId;

    /** @var array<string, mixed> */
    public array $patch;

    /**
     * @param  array<string, mixed>  $patch
     */
    public function __construct(MutationContext $context, string $orderId, array $patch)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->patch = array_intersect_key(
            $patch,
            array_flip(['guest_count', 'note', 'customer_id', 'order_type', 'table_id']),
        );
    }
}
