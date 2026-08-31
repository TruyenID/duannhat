<?php

namespace App\Models;

use App\Services\Payment\Settlement\Enums\SettlementReportBatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan-050 (#1155) — audit row per settlement-report file import (M3
 * PayPay importer; blocked on T1.0 real report file). `file_hash` is
 * UNIQUE at the DB level so re-importing the same file is a structural
 * no-op (S-01); imports are all-or-nothing per file (S-03).
 */
class SettlementReportBatch extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'connection_id',
        'provider',
        'cycle_label',
        'file_hash',
        'row_count',
        'matched_count',
        'orphan_count',
        'imported_by_id',
        'imported_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => SettlementReportBatchStatus::class,
            'row_count' => 'integer',
            'matched_count' => 'integer',
            'orphan_count' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayConnection::class, 'connection_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(PaymentSettlement::class, 'report_batch_id');
    }
}
