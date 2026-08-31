<?php

namespace App\Services\Printing\Enums;

/**
 * plan-052 P-33 [HARD] — how much a `printed` row is actually worth.
 *
 * A cheap ESC/POS machine on a raw TCP socket can tell you one thing: the
 * bytes were written. It cannot tell you the paper ran out mid-slip. Recording
 * that as the same "printed" a CloudPRNT confirm produces would lull ops into
 * trusting a number that is not true, so the ledger keeps the two apart and
 * NOTHING is ever allowed to promote `sent_only` into `confirmed`.
 */
enum PrintConfidence: string
{
    /** error_detect level A — we know the bytes left, nothing more. */
    case SentOnly = 'sent_only';
    /** The machine (or its protocol) confirmed the sheet came out. */
    case Confirmed = 'confirmed';
}
