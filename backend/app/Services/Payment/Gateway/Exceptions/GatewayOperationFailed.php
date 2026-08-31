<?php

namespace App\Services\Payment\Gateway\Exceptions;

/**
 * The provider answered at the transport layer but its own result envelope
 * reports a failure.
 *
 * Some providers signal the real outcome in a body field while returning HTTP
 * 200. An adapter that reads only the payment status then launders that
 * rejection into an unknown status, which downstream reads as "needs
 * reconciliation" — an operator chasing money that never moved — instead of
 * "the call failed". Adapters raise this instead.
 */
final class GatewayOperationFailed extends PaymentGatewayException
{
    public function __construct(
        string $correlationId,
        public readonly string $providerCode,
    ) {
        parent::__construct(
            'PAYMENT_GATEWAY_OPERATION_FAILED',
            $correlationId,
            'The payment gateway rejected the operation.',
        );
    }
}
