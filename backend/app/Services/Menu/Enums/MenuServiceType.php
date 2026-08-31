<?php

namespace App\Services\Menu\Enums;

enum MenuServiceType: string
{
    case Takeaway = 'Takeaway';
    case DineIn = 'DineIn';
    case Both = 'Both';
}
