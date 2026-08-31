<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\ValueObjects\OrderTableSetPayload;

final readonly class ChangeOrderTableCommand extends MutationCommand
{
    public string $orderId;

    public string $payloadFingerprint;

    public function __construct(
        MutationContext $context,
        string $orderId,
        public OrderTableSetPayload $payload,
        string $payloadFingerprint,
    ) {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->payloadFingerprint = self::verifiedFingerprint($payloadFingerprint, 'payloadFingerprint', $payload);
    }
}
