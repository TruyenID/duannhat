<?php

/**
 * StockTransaction Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Omnify\Modules\StockTransaction\Services\StockTransactionServiceBase;
use App\Services\Omnify\Concerns\ScopesOmnifyListToOrganization;

class StockTransactionService extends StockTransactionServiceBase
{
    // plan-040 M9 — org-scope the generated list() (StockTransaction has organization_id).
    use ScopesOmnifyListToOrganization;
}
