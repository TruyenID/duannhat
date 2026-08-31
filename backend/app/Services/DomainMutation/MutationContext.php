<?php

namespace App\Services\DomainMutation;

use InvalidArgumentException;
use JsonSerializable;

final readonly class MutationContext implements JsonSerializable
{
    public ?string $organizationId;

    public ?string $actorId;

    public string $correlationId;

    public string $idempotencyKeyHash;

    public function __construct(
        ?string $organizationId,
        ?string $actorId,
        string $correlationId,
        string $idempotencyKey,
        public ?int $expectedVersion = null,
    ) {
        $this->organizationId = MutationCommand::nullableUuid($organizationId, 'organizationId');
        $this->actorId = $actorId === null ? null : MutationCommand::uuid($actorId, 'actorId');
        $this->correlationId = MutationCommand::safeToken($correlationId, 'correlationId', 128);
        $idempotencyKey = MutationCommand::safeToken($idempotencyKey, 'idempotencyKey', 255);
        IdempotencySecretStore::put($this, $idempotencyKey);
        $this->idempotencyKeyHash = hash('sha256', $idempotencyKey);

        if ($expectedVersion !== null && $expectedVersion < 1) {
            throw new InvalidArgumentException('expectedVersion must be at least one when provided.');
        }
    }

    /** @return array{organization_id: string|null, actor_id: string|null, correlation_id: string, idempotency_key_hash: string, expected_version: int|null} */
    public function jsonSerialize(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'actor_id' => $this->actorId,
            'correlation_id' => $this->correlationId,
            'idempotency_key_hash' => $this->idempotencyKeyHash,
            'expected_version' => $this->expectedVersion,
        ];
    }

    /** Raw access is explicit and remains absent from object state, dumps and serialization. */
    public function revealIdempotencyKey(): string
    {
        return IdempotencySecretStore::reveal($this);
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
