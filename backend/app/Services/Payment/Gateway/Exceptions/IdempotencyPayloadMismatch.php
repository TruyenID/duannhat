<?php

namespace App\Services\Payment\Gateway\Exceptions;

final class IdempotencyPayloadMismatch extends PaymentGatewayException
{
    public function __construct(string $correlationId)
    {
        parent::__construct(
            'IDEMPOTENCY_PAYLOAD_MISMATCH',
            $correlationId,
            'The idempotency key was already used with a different operation payload.',
        );
    }
}
