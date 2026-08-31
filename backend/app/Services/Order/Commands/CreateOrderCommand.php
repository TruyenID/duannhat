<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\ValueObjects\OrderSelectionPayload;

final readonly class CreateOrderCommand extends MutationCommand
{
    public string $orderId;

    public string $branchId;

    public string $selectionFingerprint;

    public function __construct(
        MutationContext $context,
        string $orderId,
        string $branchId,
        public OrderSelectionPayload $payload,
        string $selectionFingerprint,
    ) {
        parent::__construct($context);
        if ($context->organizationId === null) {
            throw new \InvalidArgumentException('Create order requires an organization tenant.');
        }
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->branchId = self::uuid($branchId, 'branchId');
        $this->selectionFingerprint = self::verifiedFingerprint($selectionFingerprint, 'selectionFingerprint', $payload);
    }
}
