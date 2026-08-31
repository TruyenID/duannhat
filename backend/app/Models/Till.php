<?php

/**
 * Till Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\Till\Models\TillBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\TillFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Till — add project-specific model logic here.
 */
class Till extends TillBaseModel
{
    use AuditsActivity;
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TillFactory
    {
        return TillFactory::new();
    }

    /**
     * Scope: branch-scoped (X-Shop-Slug → branch_id).
     */
    public function scopeForBranch(Builder $query, string $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * True ⇔ Till has a session currently 'open' or 'closing' (and the
     * service has not yet cleared current_session_id). Used by
     * ResolveOpenTillSession middleware to short-circuit 409 NO_OPEN_SHIFT.
     */
    public function hasOpenSession(): bool
    {
        return $this->current_session_id !== null;
    }
}
