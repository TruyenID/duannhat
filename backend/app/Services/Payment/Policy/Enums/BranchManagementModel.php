<?php

namespace App\Services\Payment\Policy\Enums;

enum BranchManagementModel: string
{
    case HqManaged = 'hq_managed';
    case FranchiseOwned = 'franchise_owned';
    case Unresolved = 'unresolved';
}
