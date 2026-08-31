<?php

/**
 * TillCashEvent Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\TillCashEvent\Models\TillCashEventBaseModel;
use Database\Factories\TillCashEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TillCashEvent — add project-specific model logic here.
 */
class TillCashEvent extends TillCashEventBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TillCashEventFactory
    {
        return TillCashEventFactory::new();
    }

    /**
     * Plan-036 — surface the cashier / manager who recorded this event.
     * Used by the session detail page activity timeline and the Z-report
     * cash-event log so accountants can trace each in/out to an operator.
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }
}
