<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Services\Payment\Gateway\Exceptions\GatewayAuthenticationFailed;
use PayPay\OpenPaymentAPI\Controller\ClientControllerException;

final class PayPaySdkCallGuard
{
    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public static function invoke(callable $call, string $correlationId): mixed
    {
        try {
            return $call();
        } catch (ClientControllerException $exception) {
            $statusCode = (int) $exception->getCode();
            if ($statusCode === 401 || $statusCode === 403) {
                throw new GatewayAuthenticationFailed($correlationId);
            }

            throw $exception;
        }
    }
}
