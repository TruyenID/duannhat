<?php

namespace App\Services\Payment\Secret\Enums;

enum GatewaySecretPurpose: string
{
    case Api = 'api';
    case Webhook = 'webhook';
}
