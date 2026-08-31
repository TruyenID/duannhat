<?php

namespace App\Services\Payment\Policy\Enums;

enum DevicePolicyPreference: string
{
    case Inherit = 'inherit';
    case Disabled = 'disabled';
}
