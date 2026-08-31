<?php

namespace App\Services\Payment\Gateway\Exceptions;

use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;

/**
 * Plan-048 T7.5 / #1105 (J1) — the adapter-level circuit breaker is OPEN for
 * this provider+connection: repeated provider outages tripped it, and the
 * cooldown has not elapsed (or another request already holds the half-open
 * probe slot). Thrown BEFORE any provider call and before any PaymentAttempt
 * is reserved, so refusing is side-effect free.
 */
final class PaymentProviderCircuitOpen extends PaymentGatewayException
{
    public function __construct(
        PaymentGatewayProviderCodeEnum $provider,
        public readonly string $connectionId,
        string $correlationId,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(
            'PAYMENT_PROVIDER_CIRCUIT_OPEN',
            $correlationId,
            sprintf(
                'The %s payment provider is temporarily unavailable (circuit open). Retry in ~%ds.',
                $provider->value,
                max(1, $retryAfterSeconds),
            ),
        );
    }
}
