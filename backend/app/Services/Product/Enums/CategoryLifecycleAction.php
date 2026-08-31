<?php

namespace App\Services\Product\Enums;

enum CategoryLifecycleAction: string
{
    case Archive = 'archive';
    case Restore = 'restore';
}
