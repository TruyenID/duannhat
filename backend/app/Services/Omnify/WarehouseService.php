<?php

/**
 * Warehouse Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Omnify\Modules\Warehouse\Services\WarehouseServiceBase;
use App\Services\Omnify\Concerns\ScopesOmnifyListToOrganization;

class WarehouseService extends WarehouseServiceBase
{
    // plan-040 M9 — org-scope the generated list() (Warehouse has organization_id).
    use ScopesOmnifyListToOrganization;
}
