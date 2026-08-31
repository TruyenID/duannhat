<?php

namespace App\Services\Payment\Gateway\Exceptions;

use App\Services\Payment\Gateway\Enums\GatewayCapability;

final class UnsupportedPaymentOperation extends PaymentGatewayException
{
    public function __construct(
        public readonly GatewayCapability $operation,
        public readonly string $capabilityId,
        public readonly int $capabilityRevision,
        public readonly string $apiVersion,
        string $correlationId,
    ) {
        parent::__construct(
            'PAYMENT_OPERATION_UNSUPPORTED',
            $correlationId,
            "Payment operation [{$operation->value}] is unavailable for the verified capability snapshot.",
        );
    }
}
