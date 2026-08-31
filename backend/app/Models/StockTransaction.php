<?php

/**
 * StockTransaction Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\StockTransaction\Models\StockTransactionBaseModel;
use Database\Factories\StockTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StockTransaction — add project-specific model logic here.
 */
class StockTransaction extends StockTransactionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): StockTransactionFactory
    {
        return StockTransactionFactory::new();
    }

    /**
     * The user who created this transaction. Eager-loaded by
     * StockTransactionService::list() so the API can surface a small
     * `created_by` payload (id + name + email) without an N+1.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The user who approved this transaction (set when the record moves
     * from `pending` to `approved`/`completed`).
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
