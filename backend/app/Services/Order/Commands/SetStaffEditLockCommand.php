<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class SetStaffEditLockCommand extends MutationCommand
{
    public string $orderId;

    public function __construct(
        MutationContext $context,
        string $orderId,
        public ?\DateTimeInterface $lockedAt,
    ) {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
    }
}
