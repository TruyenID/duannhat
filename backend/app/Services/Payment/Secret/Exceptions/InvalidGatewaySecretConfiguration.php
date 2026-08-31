<?php

namespace App\Services\Payment\Secret\Exceptions;

use RuntimeException;

final class InvalidGatewaySecretConfiguration extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(public readonly string $reason)
    {
        $this->errorCode = 'PAYMENT_SECRET_STORE_CONFIGURATION_INVALID';
        parent::__construct('Payment gateway secret-store configuration is invalid.');
    }
}
