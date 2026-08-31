<?php

namespace App\Services\Payment\Gateway\Enums;

enum GatewayNextActionType: string
{
    case Redirect = 'redirect';
    case QrCode = 'qr_code';
    case ProviderSdk = 'provider_sdk';
    case DisplayInstructions = 'display_instructions';
    case Wait = 'wait';
}
