<?php

namespace App\Services\Payment\Orchestration\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ApplyInboxProviderEventCommand extends MutationCommand
{
    public string $providerEventRecordId;

    public function __construct(MutationContext $context, string $providerEventRecordId)
    {
        parent::__construct($context);
        $this->providerEventRecordId = self::uuid($providerEventRecordId, 'providerEventRecordId');
    }
}
