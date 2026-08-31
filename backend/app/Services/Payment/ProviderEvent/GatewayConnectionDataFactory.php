<?php

namespace App\Services\Payment\ProviderEvent;

use App\Models\PaymentGatewayConnection;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;

final class GatewayConnectionDataFactory
{
    public static function fromModel(PaymentGatewayConnection $connection): GatewayConnectionData
    {
        $connection->loadMissing('provider');

        return new GatewayConnectionData(
            (string) $connection->id,
            self::providerCode($connection),
            $connection->environment instanceof PaymentGatewayEnvironmentEnum
                ? $connection->environment
                : PaymentGatewayEnvironmentEnum::from((string) $connection->environment),
            (string) $connection->merchant_account_id,
            1,
        );
    }

    private static function providerCode(PaymentGatewayConnection $connection): PaymentGatewayProviderCodeEnum
    {
        $code = $connection->provider->code;

        return $code instanceof PaymentGatewayProviderCodeEnum
            ? $code
            : PaymentGatewayProviderCodeEnum::from((string) $code);
    }
}
