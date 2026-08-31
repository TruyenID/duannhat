<?php

/**
 * TillSettlementTenderDetail Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\TillSettlementTenderDetail\Models\TillSettlementTenderDetailBaseModel;
use Database\Factories\TillSettlementTenderDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * TillSettlementTenderDetail — add project-specific model logic here.
 */
class TillSettlementTenderDetail extends TillSettlementTenderDetailBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TillSettlementTenderDetailFactory
    {
        return TillSettlementTenderDetailFactory::new();
    }

    /**
     * See TillTenderType::casts() for the same override + rationale —
     * settlement details snapshot the (now CRUD-managed) category key,
     * which the omnify base would otherwise cast through
     * TillTenderSystemCategoryEnum and reject for custom buckets.
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'category' => 'string',
        ]);
    }
}
