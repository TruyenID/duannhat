<?php

namespace App\Services\Payment\Gateway\Exceptions;

final class WebhookPayloadConflict extends PaymentGatewayException
{
    public function __construct(string $correlationId)
    {
        parent::__construct(
            'PAYMENT_WEBHOOK_PAYLOAD_CONFLICT',
            $correlationId,
            'The provider event identity was reused with a different payload.',
        );
    }
}
