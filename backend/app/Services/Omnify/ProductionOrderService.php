<?php

/**
 * ProductionOrder Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Omnify\Modules\ProductionOrder\Services\ProductionOrderServiceBase;
use App\Services\Omnify\Concerns\ScopesOmnifyListToOrganization;

class ProductionOrderService extends ProductionOrderServiceBase
{
    // plan-040 M9 — org-scope the generated list() (ProductionOrder has organization_id).
    use ScopesOmnifyListToOrganization;
}
