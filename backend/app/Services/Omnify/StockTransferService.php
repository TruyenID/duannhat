<?php

/**
 * StockTransfer Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Omnify\Modules\StockTransfer\Services\StockTransferServiceBase;
use App\Services\Omnify\Concerns\ScopesOmnifyListToOrganization;

class StockTransferService extends StockTransferServiceBase
{
    // plan-040 M9 — org-scope the generated list() (StockTransfer has organization_id).
    use ScopesOmnifyListToOrganization;
}
