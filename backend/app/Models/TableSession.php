<?php

namespace App\Models;

use App\Http\Controllers\Api\V1\Customer\CustomerTableController;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * plan-034 — Dine-in shared-order multi-device session per table.
 *
 * A TableSession spans the lifecycle of one table-occupancy: opens on
 * the first device's QR scan, accumulates CustomerOrders (in practice
 * one open CustomerOrder per session — split-bill creates sub-orders
 * downstream), and closes on payment.
 *
 * The constraint "at most one open session per table" is enforced by
 * convention in {@see CustomerTableController}
 * — we always check for an existing `open` session before INSERTing a
 * new one. (No DB unique-index because expired/closed rows accumulate
 * for the same `table_id` over time.)
 *
 * Tracked in GitHub issue #361.
 */
class TableSession extends Model
{
    use HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'table_id',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * CustomerOrders pinned to this session. In the MVP (plan-034) there
     * is exactly one OPEN order per session — split-bill flows may add
     * sub-orders later.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(CustomerOrder::class, 'table_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
