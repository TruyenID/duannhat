<?php

namespace App\Services\Menu\Enums;

enum MenuLifecycleAction: string
{
    case Submit = 'submit';
    case Approve = 'approve';
    case Reject = 'reject';
    case Activate = 'activate';
    case Deactivate = 'deactivate';
    case Archive = 'archive';
    case Restore = 'restore';
}
