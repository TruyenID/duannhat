<?php

namespace App\Services\Menu\Enums;

enum MenuOverrideMode: string
{
    case Inherit = 'inherit';
    case Set = 'set';
    case Clear = 'clear';
}
