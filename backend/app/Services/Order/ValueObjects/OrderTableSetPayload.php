<?php

namespace App\Services\Order\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class OrderTableSetPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    /** @var list<string> */
    public array $tableIds;

    public ?string $tableSessionId;

    public function __construct(array $tableIds, ?string $tableSessionId = null)
    {
        $ids = array_map(static fn (string $id): string => MutationCommand::uuid($id, 'tableId'), array_values($tableIds));
        $this->tableIds = array_values(array_unique($ids));
        if ($this->tableIds === []) {
            throw new \InvalidArgumentException('At least one table is required.');
        }
        $this->tableSessionId = MutationCommand::nullableUuid($tableSessionId, 'tableSessionId');
    }

    public function jsonSerialize(): array
    {
        return ['table_ids' => $this->tableIds, 'table_session_id' => $this->tableSessionId];
    }
}
