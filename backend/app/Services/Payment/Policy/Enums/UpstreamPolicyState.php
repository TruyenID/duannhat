<?php

namespace App\Services\Payment\Policy\Enums;

enum UpstreamPolicyState: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Unresolved = 'unresolved';
}
