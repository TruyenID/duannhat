<?php

namespace App\Services\Payment\Orchestration\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;

final readonly class FinalizePaymentCommand extends MutationCommand
{
    public string $attemptId;

    public function __construct(MutationContext $context, string $attemptId, public GatewayPaymentResult $evidence)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->attemptId = self::uuid($attemptId, 'attemptId');
    }
}
