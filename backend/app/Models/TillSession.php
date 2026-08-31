<?php

/**
 * TillSession Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Enums\TillCountPhaseEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Omnify\Modules\TillSession\Models\TillSessionBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\TillSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TillSession — add project-specific model logic here.
 */
class TillSession extends TillSessionBaseModel
{
    use AuditsActivity;
    use HasFactory;

    /**
     * Response-only values produced by open(), declared as REAL PHP properties.
     *
     * Neither is a column on `till_sessions`. Assigning them as Eloquent
     * attributes put them into $attributes and marked the model dirty, so the
     * next save() on the returned instance emitted
     * `UPDATE till_sessions SET ..., gap_payments_claimed = ?` and died with
     * "no such column". Declaring them here means __set never fires, so the
     * value rides the object to the controller without touching persistence.
     *
     * gap_payments_claimed: how many gap payments the open() claim attributed
     * (plan-044 R2). The durable record is the `till_session.gap_claim` audit.
     * continues_chain: this open continued a handover chain (plan-046 T4.2).
     */
    public int $gapPaymentsClaimed = 0;

    public bool $continuesChain = false;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TillSessionFactory
    {
        return TillSessionFactory::new();
    }

    /**
     * Scope: sessions currently 'open' (cashier is selling).
     * Excludes 'closing' (cashier started kết-ca but not committed) — services
     * that need both call inProgress() instead.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', TillSessionStatusEnum::Open->value);
    }

    /**
     * Scope: sessions that still own till lock (open OR closing — i.e.
     * Till.current_session_id may still point here).
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TillSessionStatusEnum::Open->value,
            TillSessionStatusEnum::Closing->value,
        ]);
    }

    /**
     * Scope: branch-scoped lookup used by /pos/till/* controllers after
     * ResolvePosShop has resolved shop_id (branch_id).
     */
    public function scopeForBranch(Builder $query, string $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Scope: exclude abandoned sessions from revenue / reconciliation
     * aggregations (BR-TS06 — abandoned sessions never counted as sales).
     */
    public function scopeNotAbandoned(Builder $query): Builder
    {
        return $query->where('status', '!=', TillSessionStatusEnum::Abandoned->value);
    }

    // =========================================================================
    //  Plan-036 relations — actors + phase-scoped counts for the detail page
    //  and Z-report. Kept on the editable model (not the Omnify base) because
    //  these are aggregation concerns, not domain primitives.
    // =========================================================================

    /**
     * Override the base openingCounts to scope by count_phase. The base
     * relation pulls ALL TillCashDenominationCount rows (opening + closing);
     * the tracking surface needs them split so the detail page renders two
     * separate denomination grids.
     */
    public function openingCounts(): HasMany
    {
        return $this->hasMany(TillCashDenominationCount::class, 'session_id')
            ->where('count_phase', TillCountPhaseEnum::Opening->value);
    }

    public function closingCounts(): HasMany
    {
        return $this->hasMany(TillCashDenominationCount::class, 'session_id')
            ->where('count_phase', TillCountPhaseEnum::Closing->value);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function forceAbandonedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'force_abandoned_by_id');
    }
}
