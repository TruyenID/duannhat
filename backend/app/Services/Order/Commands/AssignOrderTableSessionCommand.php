<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class AssignOrderTableSessionCommand extends MutationCommand
{
    public string $orderId;

    public string $tableSessionId;

    public function __construct(MutationContext $context, string $orderId, string $tableSessionId)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->tableSessionId = self::uuid($tableSessionId, 'tableSessionId');
    }
}
