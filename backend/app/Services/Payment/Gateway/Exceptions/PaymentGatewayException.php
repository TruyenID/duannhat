<?php

namespace App\Services\Payment\Gateway\Exceptions;

use RuntimeException;

abstract class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $correlationId,
        string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}
