<?php

namespace App\Services\Payment\Orchestration\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;

final readonly class ReconcilePaymentRefundCommand extends MutationCommand
{
    public string $refundId;

    public function __construct(MutationContext $context, string $refundId, public GatewayRefundResult $evidence)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->refundId = self::uuid($refundId, 'refundId');
    }
}
