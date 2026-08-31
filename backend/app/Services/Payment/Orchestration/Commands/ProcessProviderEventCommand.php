<?php

namespace App\Services\Payment\Orchestration\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;

final readonly class ProcessProviderEventCommand extends MutationCommand
{
    public string $connectionId;

    public function __construct(MutationContext $context, string $connectionId, public VerifiedGatewayEvent $event)
    {
        parent::__construct($context);
        $this->connectionId = self::uuid($connectionId, 'connectionId');
    }
}
