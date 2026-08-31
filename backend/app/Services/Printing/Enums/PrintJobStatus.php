<?php

namespace App\Services\Printing\Enums;

/**
 * plan-052 — job lifecycle vocabulary, borrowed WHOLESALE from IPP/RFC 8011
 * (`pending → processing → completed | aborted | canceled`) so an operator who
 * has ever used any print system reads it without a glossary (STANDARDS §1).
 *
 * `needs_attention` is the one addition: the ACK-lost state (P-03) that no
 * remote party can resolve — "we sent it, nobody confirmed" is genuinely NOT
 * the same as printed and NOT the same as failed, and pretending otherwise is
 * exactly how a shop ends up with two originals of one invoice (PR1).
 */
enum PrintJobStatus: string
{
    case Queued = 'queued';
    case Delivering = 'delivering';
    case Printed = 'printed';
    case Failed = 'failed';
    case NeedsAttention = 'needs_attention';
    case Expired = 'expired';

    /** Terminal states never transition again. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Printed, self::Failed, self::Expired => true,
            self::Queued, self::Delivering, self::NeedsAttention => false,
        };
    }

    /**
     * States a job can still be retried FROM (by whichever tier owns the
     * queue). `needs_attention` is retryable only for kinds the retry matrix
     * allows — money documents never are (P-05).
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Failed, self::NeedsAttention => true,
            default => false,
        };
    }
}
