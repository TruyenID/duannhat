<?php

namespace App\Services\Payment\Orchestration\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class PaymentAllocationPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $allocationId;

    public string $billLabel;

    /** @var list<string> */
    public array $orderItemIds;

    public function __construct(string $allocationId, string $billLabel, public int $amountMinor, public ?int $personIndex = null, array $orderItemIds = [])
    {
        $this->allocationId = MutationCommand::uuid($allocationId, 'allocationId');
        $this->billLabel = MutationCommand::safeToken($billLabel, 'billLabel', 100);
        if ($amountMinor < 1 || ($personIndex !== null && $personIndex < 1)) {
            throw new \InvalidArgumentException('Payment allocation values are invalid.');
        }
        $this->orderItemIds = MutationCommand::canonicalSet(array_map(static fn (string $id): string => MutationCommand::uuid($id, 'orderItemId'), $orderItemIds), static fn (string $id): string => $id, 'orderItemIds');
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
