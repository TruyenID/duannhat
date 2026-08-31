<?php

/**
 * StockAlert Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Omnify\Modules\StockAlert\Services\StockAlertServiceBase;
use App\Services\Omnify\Concerns\ScopesOmnifyListToOrganization;

class StockAlertService extends StockAlertServiceBase
{
    // plan-040 M9 — org-scope the generated list() (StockAlert has organization_id).
    use ScopesOmnifyListToOrganization;
}
