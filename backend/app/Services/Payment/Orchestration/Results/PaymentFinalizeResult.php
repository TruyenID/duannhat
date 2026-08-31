<?php

namespace App\Services\Payment\Orchestration\Results;

use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class PaymentFinalizeResult
{
    public string $attemptId;

    public function __construct(
        string $attemptId,
        public int $version,
        public PaymentAttemptOutcome $outcome,
        public int $ledgerNetMinor,
        public bool $orderSettlementRequired,
    ) {
        if ($version < 1 || $ledgerNetMinor < 0) {
            throw new InvalidArgumentException('Final payment outcome is invalid.');
        }

        $this->attemptId = MutationCommand::uuid($attemptId, 'attemptId');
    }
}
