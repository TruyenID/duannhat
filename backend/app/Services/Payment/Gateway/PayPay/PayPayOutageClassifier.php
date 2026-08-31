<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Services\Payment\Gateway\Contracts\ProviderOutageClassifier;
use PayPay\OpenPaymentAPI\Controller\ClientControllerException;
use Throwable;

/**
 * #1105 (J1) — PayPay outages: SDK controller exceptions carrying no HTTP
 * status (transport-level, code 0) or a provider 5xx. 4xx (auth, validation,
 * user-cancel) are mapped business outcomes, never outages.
 */
final class PayPayOutageClassifier implements ProviderOutageClassifier
{
    public function isProviderOutage(Throwable $exception): bool
    {
        if (! $exception instanceof ClientControllerException) {
            return false;
        }

        $status = (int) $exception->getCode();

        return $status === 0 || $status >= 500;
    }
}
