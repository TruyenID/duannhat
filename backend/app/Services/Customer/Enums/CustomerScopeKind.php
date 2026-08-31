<?php

namespace App\Services\Customer\Enums;

enum CustomerScopeKind: string
{
    case GlobalAccount = 'global_account';
    case TenantCrm = 'tenant_crm';
}
