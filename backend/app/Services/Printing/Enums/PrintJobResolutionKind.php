<?php

namespace App\Services\Printing\Enums;

/**
 * plan-052 M2 (T2.2) — what a manager DID about a job the pipeline could not
 * settle by itself.
 *
 * There are exactly two honest answers, and neither of them is "try again":
 *
 *   - the paper exists, produced some other way → `printed_by_hand`;
 *   - the paper is not needed any more (shift over, table left, guest gone)
 *     → `discarded`.
 *
 * A third case — "print it again" — deliberately has NO value here. Reprinting
 * a money document is an accounting event that must consume a 「Bản in #N」 and
 * pass the reprint gate (P-10), so it goes through that door and lands in the
 * ledger as its own row. If this enum could say "retry", the Print-jobs screen
 * would become a one-click way to produce a second original of an インボイス —
 * exactly the failure RISKS PR1 exists to prevent.
 */
enum PrintJobResolutionKind: string
{
    /** A human produced the document another way (hand-written, other printer, PDF). */
    case PrintedByHand = 'printed_by_hand';

    /** The document is no longer needed; the job is closed unprinted, on purpose. */
    case Discarded = 'discarded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
