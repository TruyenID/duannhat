<?php

namespace App\Services\Payment\Gateway\Enums;

enum CapabilitySupport: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case Conditional = 'conditional';
}
