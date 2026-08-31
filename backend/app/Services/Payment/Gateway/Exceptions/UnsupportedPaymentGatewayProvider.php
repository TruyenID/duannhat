<?php

namespace App\Services\Payment\Gateway\Exceptions;

use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;

final class UnsupportedPaymentGatewayProvider extends PaymentGatewayException
{
    /** @param list<PaymentGatewayProviderCodeEnum> $configuredProviders */
    public function __construct(
        public readonly PaymentGatewayProviderCodeEnum $provider,
        public readonly array $configuredProviders,
        string $correlationId,
    ) {
        parent::__construct(
            'PAYMENT_GATEWAY_PROVIDER_UNSUPPORTED',
            $correlationId,
            "Payment gateway provider [{$provider->value}] is not configured.",
        );
    }
}
