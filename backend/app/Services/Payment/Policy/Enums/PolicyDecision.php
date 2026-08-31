<?php

namespace App\Services\Payment\Policy\Enums;

enum PolicyDecision: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Unresolved = 'unresolved';
}
