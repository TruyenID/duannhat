<?php

namespace App\Services\Payment\Orchestration\Results;

use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class PaymentPrepareResult
{
    public string $attemptId;

    public function __construct(string $attemptId, public int $version, public PaymentAttemptOutcome $outcome)
    {
        if ($version < 1) {
            throw new InvalidArgumentException('version must be positive.');
        }

        $this->attemptId = MutationCommand::uuid($attemptId, 'attemptId');
    }
}
