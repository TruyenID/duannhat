<?php

/**
 * MaterialBatch Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Omnify\Modules\MaterialBatch\Services\MaterialBatchServiceBase;
use App\Services\Omnify\Concerns\ScopesOmnifyListToOrganization;

class MaterialBatchService extends MaterialBatchServiceBase
{
    // plan-040 M9 — org-scope the generated list() (MaterialBatch has organization_id).
    use ScopesOmnifyListToOrganization;
}
