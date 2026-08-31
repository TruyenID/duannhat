<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TillTenderCategory — runtime-configurable category for till tender types.
 *
 * Replaces the hard-baked TillTenderSystemCategoryEnum at the application level
 * (the PHP enum still exists for legacy code-paths but the controllers
 * accept any active row from this table). System rows (is_system=1,
 * branch_id=null) keep `key` aligned with the original enum values so the
 * reconciliation math in TillSessionService::reconcile() continues to
 * compute per-category expected_amount without surprises. Custom rows have
 * is_system=0 and any unique slug; their expected_amount is computed via
 * `payment_method_code` on each member tender (no implicit mapping).
 *
 * Hand-written (no Omnify schema). Add a YAML schema later if/when the
 * generated CRUD boilerplate becomes valuable; for now the surface is
 * small enough that the direct controller + service is cheaper.
 */
class TillTenderCategory extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'till_tender_categories';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'key',
        'name',
        'sort_order',
        'is_active',
        'is_system',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * TillTenderType rows wearing this category's key. Not enforced by FK
     * (category is a free-form string column on till_tender_types so legacy
     * enum data still resolves); join on (organization_id, key) when you
     * actually need the relation.
     */
    public function tenderTypes(): HasMany
    {
        return $this->hasMany(TillTenderType::class, 'category', 'key')
            ->where('till_tender_types.organization_id', $this->organization_id);
    }
}
