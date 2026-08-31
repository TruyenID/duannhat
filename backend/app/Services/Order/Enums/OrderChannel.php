<?php

namespace App\Services\Order\Enums;

enum OrderChannel: string
{
    case Pos = 'pos';
    case Kiosk = 'kiosk';
    case CustomerWeb = 'customer_web';
    case Workstation = 'workstation';
    case Api = 'api';
}
