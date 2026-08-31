<?php

namespace App\Services\Payment\Policy\Enums;

enum PolicyLayer: string
{
    case Ownership = 'ownership';
    case Provider = 'provider';
    case Connection = 'connection';
    case Capability = 'capability';
    case OwnerPolicy = 'owner_policy';
    case Shop = 'shop';
    case Device = 'device';
    case Runtime = 'runtime';
}
