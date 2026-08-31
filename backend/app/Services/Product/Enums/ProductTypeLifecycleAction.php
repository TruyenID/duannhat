<?php

namespace App\Services\Product\Enums;

enum ProductTypeLifecycleAction: string
{
    case Archive = 'archive';
    case Restore = 'restore';
    case ToggleStatus = 'toggle-status';
}
