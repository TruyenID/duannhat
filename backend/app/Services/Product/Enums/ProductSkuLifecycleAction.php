<?php

namespace App\Services\Product\Enums;

enum ProductSkuLifecycleAction: string
{
    case Archive = 'archive';
    case Restore = 'restore';
    case ToggleStatus = 'toggle-status';
}
