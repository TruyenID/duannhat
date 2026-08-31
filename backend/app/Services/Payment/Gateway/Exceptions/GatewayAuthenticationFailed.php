<?php

namespace App\Services\Payment\Gateway\Exceptions;

final class GatewayAuthenticationFailed extends PaymentGatewayException
{
    public function __construct(string $correlationId)
    {
        parent::__construct(
            'PAYMENT_GATEWAY_AUTHENTICATION_FAILED',
            $correlationId,
            'The payment gateway connection could not be authenticated.',
        );
    }
}
