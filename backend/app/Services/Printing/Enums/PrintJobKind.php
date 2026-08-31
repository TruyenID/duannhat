<?php

namespace App\Services\Printing\Enums;

/**
 * plan-052 — what a print job IS. The kind, not the transport, decides
 * whether a failed job may be retried without a human (P-05) and how long it
 * stays worth printing (P-06).
 */
enum PrintJobKind: string
{
    case Receipt = 'receipt';
    case Kitchen = 'kitchen';
    case Bar = 'bar';
    case RedInvoice = 'red_invoice';
    case DebtSlip = 'debt_slip';
    case Report = 'report';
    case Label = 'label';
    /**
     * P-41 — the printer setup wizard's diagnostic sheet. It rides the ledger
     * like everything else so a shop can see it failed, but it is NOT a money
     * document: it never consumes a 「Bản in #N」 and never pollutes invoice
     * audit.
     */
    case Diagnostic = 'diagnostic';

    /**
     * Money documents (chứng từ tiền). Reprinting one is an accounting event:
     * it needs authorization (P-10) and it may NEVER be auto-retried (PR1) —
     * an ACK-lost receipt that a machine reprints on its own is how two
     * originals of one インボイス come into existence.
     */
    public function isMoneyDocument(): bool
    {
        return match ($this) {
            self::Receipt, self::RedInvoice, self::DebtSlip => true,
            default => false,
        };
    }
}
