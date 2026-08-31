<?php

namespace App\Services\Payment\Gateway\Exceptions;

use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use RuntimeException;

final class InvalidPaymentGatewayDriver extends RuntimeException
{
    public const ERROR_CODE = 'PAYMENT_GATEWAY_DRIVER_INVALID';

    public readonly string $errorCode;

    public function __construct(
        public readonly ?PaymentGatewayProviderCodeEnum $provider,
        public readonly string $reason,
    ) {
        $this->errorCode = self::ERROR_CODE;

        parent::__construct($provider === null
            ? 'Payment gateway driver configuration is invalid.'
            : "Payment gateway driver configuration for [{$provider->value}] is invalid.");
    }
}
