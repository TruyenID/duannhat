<?php

/**
 * TableTemplate Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\TableTemplate\Models\TableTemplateBaseModel;
use Database\Factories\TableTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * TableTemplate — add project-specific model logic here.
 */
class TableTemplate extends TableTemplateBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TableTemplateFactory
    {
        return TableTemplateFactory::new();
    }

    //
}
