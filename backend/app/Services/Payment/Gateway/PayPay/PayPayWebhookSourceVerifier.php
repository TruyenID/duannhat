<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;

/**
 * PayPay OPA incoming webhooks are secured by source-IP allowlisting, not a
 * separate webhook signing secret — see PayPay OPA "Webhook Setup" and
 * integration.paypay.ne.jp FAQ 4414062832143.
 */
final class PayPayWebhookSourceVerifier
{
    public function isAllowed(?string $clientIp, PaymentGatewayEnvironmentEnum $environment): bool
    {
        if ($clientIp === null || trim($clientIp) === '') {
            return false;
        }

        $clientIp = trim($clientIp);

        /** @var list<string> $allowed */
        $allowed = config(
            $environment === PaymentGatewayEnvironmentEnum::Live
                ? 'services.paypay.webhook_source_ips.live'
                : 'services.paypay.webhook_source_ips.sandbox',
            [],
        );

        return in_array($clientIp, $allowed, true);
    }
}
