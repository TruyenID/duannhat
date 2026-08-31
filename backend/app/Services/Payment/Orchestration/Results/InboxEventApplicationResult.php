<?php

namespace App\Services\Payment\Orchestration\Results;

use App\Services\DomainMutation\MutationCommand;

/**
 * Outcome of applying one inbox provider event.
 *
 * `outcome` is the applicator's own string (orchestrator_finalized,
 * orchestrator_refund_reconciled, ignored_transition, …) — it is persisted on
 * the inbox row and is what operators grep. `routedThroughOrchestrator` lifts the
 * `orchestrator_` prefix convention into a typed field so callers stop
 * re-deriving it from the string.
 */
final readonly class InboxEventApplicationResult
{
    public string $outcome;

    public function __construct(string $outcome)
    {
        $this->outcome = MutationCommand::safeToken($outcome, 'outcome', 255);
    }

    public function routedThroughOrchestrator(): bool
    {
        return str_starts_with($this->outcome, 'orchestrator_');
    }
}
