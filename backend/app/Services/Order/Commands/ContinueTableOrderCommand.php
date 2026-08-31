<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\ValueObjects\OrderSelectionPayload;

/**
 * Plan-021 take-over: retire whatever is active on the given tables (close
 * fully paid, void anything still owing — issue #554), free the tables, then
 * create a fresh order with the supplied selection. Idempotent on free tables.
 *
 * Shaped like {@see CreateOrderCommand}: this is a creation, so there is no
 * expected-version precondition — the selection payload (which MUST carry the
 * tables being taken over) is fingerprint-verified instead.
 */
final readonly class ContinueTableOrderCommand extends MutationCommand
{
    public string $branchId;

    public string $selectionFingerprint;

    public function __construct(
        MutationContext $context,
        string $branchId,
        public OrderSelectionPayload $payload,
        string $selectionFingerprint,
    ) {
        parent::__construct($context);

        if ($context->organizationId === null) {
            throw new \InvalidArgumentException('Continue-table requires an organization tenant.');
        }

        if ($payload->tableIds === []) {
            throw new \InvalidArgumentException('Continue-table requires at least one table to take over.');
        }

        $this->branchId = self::uuid($branchId, 'branchId');
        $this->selectionFingerprint = self::verifiedFingerprint($selectionFingerprint, 'selectionFingerprint', $payload);
    }
}
