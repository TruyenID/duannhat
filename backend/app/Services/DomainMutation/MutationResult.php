<?php

namespace App\Services\DomainMutation;

use InvalidArgumentException;
use JsonSerializable;

final readonly class MutationResult implements JsonSerializable
{
    public string $aggregateId;

    public function __construct(
        string $aggregateId,
        public ?int $version,
        public bool $changed,
    ) {
        $this->aggregateId = MutationCommand::uuid($aggregateId, 'aggregateId');

        if ($version !== null && $version < 1) {
            throw new InvalidArgumentException('version must be at least one.');
        }
    }

    /** @return array{aggregate_id: string, version: int|null, changed: bool} */
    public function jsonSerialize(): array
    {
        return [
            'aggregate_id' => $this->aggregateId,
            'version' => $this->version,
            'changed' => $this->changed,
        ];
    }
}
