<?php

namespace App\Services\Payment\Gateway\Stripe;

use App\Services\Payment\Gateway\Contracts\ProviderOutageClassifier;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * #1105 (J1) — Stripe outages: transport failures and provider 5xx. Declines
 * (CardException, 402) and other 4xx API errors are business outcomes, never
 * outages.
 */
final class StripeOutageClassifier implements ProviderOutageClassifier
{
    public function isProviderOutage(Throwable $exception): bool
    {
        if ($exception instanceof ApiConnectionException) {
            return true;
        }

        if ($exception instanceof ApiErrorException) {
            return (int) ($exception->getHttpStatus() ?? 0) >= 500;
        }

        return false;
    }
}
