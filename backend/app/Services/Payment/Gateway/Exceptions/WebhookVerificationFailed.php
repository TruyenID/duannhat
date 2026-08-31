<?php

namespace App\Services\Payment\Gateway\Exceptions;

final class WebhookVerificationFailed extends PaymentGatewayException
{
    public function __construct(string $correlationId)
    {
        parent::__construct(
            'PAYMENT_WEBHOOK_VERIFICATION_FAILED',
            $correlationId,
            'The provider webhook could not be verified.',
        );
    }
}
