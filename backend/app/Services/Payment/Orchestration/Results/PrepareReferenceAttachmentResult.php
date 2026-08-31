<?php

namespace App\Services\Payment\Orchestration\Results;

use App\Services\DomainMutation\MutationCommand;

/**
 * Outcome of stamping a customer-web prepare reference onto an attempt.
 *
 * `attached` is false when the attempt row did not match the command's tenant —
 * the caller gets a definite answer instead of the previous silent void.
 */
final readonly class PrepareReferenceAttachmentResult
{
    public string $attemptId;

    public function __construct(string $attemptId, public bool $attached)
    {
        $this->attemptId = MutationCommand::uuid($attemptId, 'attemptId');
    }
}
