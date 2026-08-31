<?php

namespace App\Services\Payment\Gateway\Enums;

enum CapabilityOperator: string
{
    case Equals = 'equals';
    case IsTrue = 'is_true';
}
