<?php

namespace App\Services\Payment\Settlement\Enums;

/**
 * Plan-050 — payout lifecycle.
 *
 * pending / in_transit / paid / failed / hold mirror the provider's own
 * payout states (DESIGN.md §2). `mismatch` is OUR verdict layered on top:
 * the payout is paid per the provider but Σ net of its settlement rows does
 * not equal the payout net (S-12) — it must stay loudly queryable for the
 * reconcile sweep, and is never auto-balanced with a synthetic row.
 */
enum GatewayPayoutStatus: string
{
    case Pending = 'pending';
    case InTransit = 'in_transit';
    case Paid = 'paid';
    case Failed = 'failed';
    case Hold = 'hold';
    case Mismatch = 'mismatch';
}
