<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use JsonSerializable;

final readonly class GatewayRequestContext extends GatewayValue implements JsonSerializable
{
    public string $operationId;

    public string $idempotencyKey;

    public string $correlationId;

    public function __construct(string $operationId, string $idempotencyKey, string $correlationId)
    {
        $this->operationId = self::uuid($operationId, 'operationId');
        $this->idempotencyKey = self::requestKey($idempotencyKey, 'idempotencyKey');
        $this->correlationId = self::requestKey($correlationId, 'correlationId');
    }

    /** @return array{operation_id: string, idempotency_key: string, correlation_id: string} */
    public function jsonSerialize(): array
    {
        return [
            'operation_id' => $this->operationId,
            'idempotency_key' => $this->idempotencyKey,
            'correlation_id' => $this->correlationId,
        ];
    }
}
