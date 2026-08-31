<?php

namespace App\Services\Payment\Settlement\Enums;

/**
 * Plan-050 — typed money-event on the gateway side (Stripe balance
 * transaction model: everything is a typed transaction, not only payments).
 */
enum SettlementKind: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case DisputeWithdrawal = 'dispute_withdrawal';
    case DisputeReversal = 'dispute_reversal';
    case DisputeFee = 'dispute_fee';
    case AccountFee = 'account_fee';
    case Manual = 'manual';
}
