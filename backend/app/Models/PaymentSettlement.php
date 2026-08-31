<?php

namespace App\Models;

use App\Services\Payment\Settlement\Enums\SettlementKind;
use App\Services\Payment\Settlement\Enums\SettlementSource;
use App\Services\Payment\Settlement\Enums\SettlementStatus;
use App\Services\Payment\Settlement\SettlementRowAssembler;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Plan-050 (#1155) — one row per money-event on the GATEWAY side of the
 * quán ↔ gateway sub-ledger: payment, refund, dispute withdrawal/fee/
 * reversal, account-level fee, or a manually classified unknown (S-13).
 *
 * Amounts are SIGNED bigint minor units (S-11). The row invariant
 * `net = gross - fee - fee_tax` (S-15) is enforced by
 * {@see SettlementRowAssembler} — the only
 * sanctioned row builder — before anything is persisted.
 *
 * Idempotency is a DB constraint, not app logic: UNIQUE (provider,
 * external_ref) means replaying a webhook or re-importing a report can never
 * double a row (S-02, G3).
 *
 * S-24 settled-guard: a `reconciled` row is IMMUTABLE (mirrors the
 * PaymentPolicyRevision immutability guard). Corrections are new append-only
 * rows (kind=manual with a reason), never edits. The single sanctioned
 * exception is releasing rows back to `pending_payout` when their payout
 * FAILS at the provider (S-10) — via {@see whileReleasingReconciled()}.
 */
class PaymentSettlement extends Model
{
    use HasFactory;
    use HasUuids;

    private static bool $releasingReconciled = false;

    protected $fillable = [
        'connection_id',
        'provider',
        'kind',
        'order_payment_id',
        'payment_attempt_id',
        'gross_minor',
        'fee_minor',
        'fee_tax_minor',
        'net_minor',
        'currency',
        'source',
        'external_ref',
        'report_batch_id',
        'payout_id',
        'provider_settled_at',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SettlementKind::class,
            'source' => SettlementSource::class,
            'status' => SettlementStatus::class,
            'gross_minor' => 'integer',
            'fee_minor' => 'integer',
            'fee_tax_minor' => 'integer',
            'net_minor' => 'integer',
            'provider_settled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $settlement): void {
            // getRawOriginal: the status column is enum-cast, so getOriginal
            // would return the enum instance — compare the stored string.
            if ($settlement->getRawOriginal('status') === SettlementStatus::Reconciled->value
                && ! self::$releasingReconciled) {
                throw new LogicException(
                    'Reconciled payment settlements are immutable (S-24) — append a manual correction row instead.',
                );
            }
        });

        static::deleting(function (self $settlement): void {
            if ($settlement->getRawOriginal('status') === SettlementStatus::Reconciled->value) {
                throw new LogicException(
                    'Reconciled payment settlements are append-only (S-24) — they can never be deleted.',
                );
            }
        });
    }

    /**
     * S-10 — the ONLY sanctioned write path through the reconciled guard:
     * when a payout FAILS at the provider, its reconciled rows return to
     * `pending_payout` so the provider's next payout can pick them up. Any
     * other reconciled-row mutation still throws inside the callback.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function whileReleasingReconciled(callable $callback): mixed
    {
        self::$releasingReconciled = true;

        try {
            return $callback();
        } finally {
            self::$releasingReconciled = false;
        }
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayConnection::class, 'connection_id');
    }

    public function orderPayment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'order_payment_id');
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function reportBatch(): BelongsTo
    {
        return $this->belongsTo(SettlementReportBatch::class, 'report_batch_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(GatewayPayout::class, 'payout_id');
    }
}
