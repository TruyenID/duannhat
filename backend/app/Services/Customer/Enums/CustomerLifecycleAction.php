<?php

namespace App\Services\Customer\Enums;

enum CustomerLifecycleAction: string
{
    case Archive = 'archive';
    case Restore = 'restore';
}
