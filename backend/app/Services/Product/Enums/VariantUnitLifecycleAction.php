<?php

namespace App\Services\Product\Enums;

enum VariantUnitLifecycleAction: string
{
    case Remove = 'remove';
    case MakeBase = 'make_base';
}
