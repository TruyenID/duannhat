<?php

/**
 * MoneyReconciliationTask Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MoneyReconciliationTask\Models\MoneyReconciliationTaskBaseModel;
use Database\Factories\MoneyReconciliationTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MoneyReconciliationTask — add project-specific model logic here.
 */
class MoneyReconciliationTask extends MoneyReconciliationTaskBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MoneyReconciliationTaskFactory
    {
        return MoneyReconciliationTaskFactory::new();
    }

    //
}
