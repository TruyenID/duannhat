<?php

namespace App\Models;

use App\Services\Payment\Settlement\Enums\GatewayPayoutStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan-050 (#1155) — one row per gateway → bank transfer (Stripe payout /
 * PayPay 振込 line). Reconciled when Σ net of its attached
 * payment_settlements equals `net_minor` (verified, never assumed — S-12);
 * a shortfall marks the payout `mismatch` and is NEVER auto-balanced.
 *
 * `net_minor` is signed: a period where refunds exceed sales produces a
 * legitimate negative (debit) payout (S-11). Dates follow the GATEWAY's
 * calendar, not branch business time (#1091 / DESIGN §5).
 */
class GatewayPayout extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'connection_id',
        'provider',
        'external_payout_id',
        'expected_arrival_date',
        'paid_at',
        'gross_minor',
        'fee_minor',
        'net_minor',
        'currency',
        'status',
        'reconciled_at',
        'bank_ref',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => GatewayPayoutStatus::class,
            'gross_minor' => 'integer',
            'fee_minor' => 'integer',
            'net_minor' => 'integer',
            'expected_arrival_date' => 'date',
            'paid_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayConnection::class, 'connection_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(PaymentSettlement::class, 'payout_id');
    }
}
