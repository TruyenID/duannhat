<?php

namespace App\Services\Payment\Settlement\Enums;

/** Plan-050 — where a settlement row's numbers came from. */
enum SettlementSource: string
{
    case Api = 'api';
    case Report = 'report';
    case Manual = 'manual';
}
