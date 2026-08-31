<?php

namespace App\Models;

use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Enums\PrintTemplateStatus;
use App\Services\Print\TemplateResolver;
use Database\Factories\PrintTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * plan-053 (#1171) — one VERSION of one print template at one scope.
 *
 * Published rows are immutable (TR-08): the service layer refuses every
 * mutation on a non-draft row, and the only way forward is a new version.
 * Rows are never hard-deleted — a reprint must be able to render with the
 * exact version its job recorded (TR-28/TR-39).
 *
 * `effective_from` is a BRANCH-LOCAL WALL CLOCK, not an instant (#1091): HQ
 * schedules a switch for "2026-08-01 00:00" and a Tokyo branch flips two
 * hours before a Hanoi one, the same way a breakfast menu window works. It is
 * therefore compared against `BusinessClock::now($branchId)` formatted as a
 * wall clock — never against `now()`. See {@see TemplateResolver}.
 */
class PrintTemplate extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'brand_id',
        'branch_id',
        'kind',
        'scope',
        'version',
        'status',
        'definition',
        'shop_editable',
        'effective_from',
        'parent_version_id',
        'notes',
        'created_by_id',
        'published_by_id',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PrintTemplateKind::class,
            'scope' => PrintTemplateScope::class,
            'status' => PrintTemplateStatus::class,
            'version' => 'integer',
            'definition' => 'array',
            'shop_editable' => 'array',
            // NOT 'datetime': effective_from is a branch-local wall-clock
            // SPEC. Casting it to a Carbon instant would invite a timezone
            // conversion that silently shifts the switch-over by the branch's
            // UTC offset (#1091). Kept as the raw 'Y-m-d H:i:s' string and
            // compared as a wall clock.
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PrintTemplateFactory
    {
        return PrintTemplateFactory::new();
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function parentVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    /** Brand-layer rows for a brand (layer 1). */
    public function scopeBrandLayer(Builder $query, string $brandId): Builder
    {
        return $query
            ->where('scope', PrintTemplateScope::Brand->value)
            ->where('brand_id', $brandId)
            ->whereNull('branch_id');
    }

    /** Shop-layer rows for one branch (layer 2). */
    public function scopeShopLayer(Builder $query, string $brandId, string $branchId): Builder
    {
        return $query
            ->where('scope', PrintTemplateScope::Shop->value)
            ->where('brand_id', $brandId)
            ->where('branch_id', $branchId);
    }

    /** True while the row may still be edited in place. */
    public function isDraft(): bool
    {
        return $this->status === PrintTemplateStatus::Draft;
    }
}
