<?php

/**
 * Table Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\Table\Models\TableBaseModel;
use Database\Factories\TableFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Table — add project-specific model logic here.
 */
class Table extends TableBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TableFactory
    {
        return TableFactory::new();
    }

    /**
     * Convenience: the most recent status transition for this table.
     * Used by the table grid to render "occupied for 23 min" labels.
     */
    public function lastStatusChange(): HasOne
    {
        return $this->hasOne(TableStatusChange::class)->latestOfMany('changed_at');
    }
}
