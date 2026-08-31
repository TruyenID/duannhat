<?php

namespace App\Services\Payment\Secret\Exceptions;

use RuntimeException;

final class GatewaySecretResolutionFailed extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(public readonly string $correlationId)
    {
        $this->errorCode = 'PAYMENT_SECRET_RESOLUTION_FAILED';
        parent::__construct('The authorized payment gateway secret is unavailable.');
    }
}
