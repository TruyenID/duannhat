<?php

namespace App\Services\Payment\Gateway\Enums;

enum GatewayCapability: string
{
    case Create = 'create';
    case Authorize = 'authorize';
    case RetrievePayment = 'retrieve_payment';
    case Capture = 'capture';
    case Cancel = 'cancel';
    case Refund = 'refund';
    case RetrieveRefund = 'retrieve_refund';
    case WebhookVerification = 'webhook_verification';
}
