<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\ValueObjects\OrderHeaderPatch;

final readonly class ReviseOrderHeaderCommand extends MutationCommand
{
    public string $orderId;

    public function __construct(MutationContext $context, string $orderId, public OrderHeaderPatch $patch)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
    }
}
