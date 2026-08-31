<?php

/**
 * StockCount Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Omnify\Modules\StockCount\Services\StockCountServiceBase;
use App\Services\Omnify\Concerns\ScopesOmnifyListToOrganization;

class StockCountService extends StockCountServiceBase
{
    // plan-040 M9 — org-scope the generated list() (StockCount has organization_id).
    use ScopesOmnifyListToOrganization;
}
