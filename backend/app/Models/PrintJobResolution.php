<?php

namespace App\Models;

use App\Services\Printing\Enums\PrintJobResolutionKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plan-052 M2 — a manager's decision about one print job.
 *
 * Append-only by construction: `print_job_id` is UNIQUE, so the FIRST decision
 * is the one that stands. Re-resolving returns it unchanged rather than
 * rewriting who decided and why — an audit row that a later click can silently
 * replace is not an audit row.
 */
class PrintJobResolution extends Model
{
    // Bảng dùng khoá UUID; trước đây model này viết tay nên thiếu trait,
    // và chỉ lộ ra khi bảng có schema Omnify kèm factory sinh tự động.
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'print_job_id',
        'organization_id',
        'branch_id',
        'resolution',
        'reason',
        'resolved_by_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolution' => PrintJobResolutionKind::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function printJob(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'print_job_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }
}
