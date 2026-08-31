<?php

/**
 * WarehouseMember Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\WarehouseMember\Models\WarehouseMemberBaseModel;
use Database\Factories\WarehouseMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WarehouseMember — add project-specific model logic here.
 */
class WarehouseMember extends WarehouseMemberBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): WarehouseMemberFactory
    {
        return WarehouseMemberFactory::new();
    }

    /**
     * Get the user that this member belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
