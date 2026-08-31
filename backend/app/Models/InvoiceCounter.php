<?php

/**
 * InvoiceCounter Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Models\Concerns\RefusesKeylessWrites;
use App\Omnify\Modules\InvoiceCounter\Models\InvoiceCounterBaseModel;
use Database\Factories\InvoiceCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * InvoiceCounter — add project-specific model logic here.
 */
class InvoiceCounter extends InvoiceCounterBaseModel
{
    use HasFactory;
    use RefusesKeylessWrites;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): InvoiceCounterFactory
    {
        return InvoiceCounterFactory::new();
    }

    //
}
