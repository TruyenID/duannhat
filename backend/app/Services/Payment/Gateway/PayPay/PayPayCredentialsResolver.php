<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;

final class PayPayCredentialsResolver
{
    private const SYNTHETIC_REFERENCE_PREFIX = 'orchestrator:';

    public function forConnection(GatewayConnectionData $connection): PayPayCredentials
    {
        // A synthetic bootstrap reference is an internal routing id, not a
        // PayPay merchant. It exists because the connection uniqueness index has
        // no organization_id, so the real merchant id — global to the deployment
        // — cannot be stored per tenant. Mirrors StripeConnectScope refusing
        // anything that is not a real `acct_` id.
        $assumeMerchant = $connection->merchantAccountReference;
        if (str_starts_with($assumeMerchant, self::SYNTHETIC_REFERENCE_PREFIX)) {
            $assumeMerchant = '';
        }

        return new PayPayCredentials(
            (string) config('services.paypay.api_key', ''),
            (string) config('services.paypay.api_secret', ''),
            $assumeMerchant !== '' ? $assumeMerchant : (string) config('services.paypay.merchant_id', ''),
            config('services.paypay.webhook_secret') !== null
                ? (string) config('services.paypay.webhook_secret')
                : null,
        );
    }
}
