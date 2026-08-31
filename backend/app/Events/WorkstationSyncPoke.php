<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * #1175 phase 3 — contentless "something changed, pull now" hint for the
 * workstation of one branch.
 *
 * The payload is EMPTY by design (Replicache-style poke): a listener that
 * hears it does exactly one thing — kick its pull tick early. No data rides
 * the wire, so there is no contract to break and nothing to replay; a lost
 * poke costs only latency because the periodic pull loop remains the sole
 * source of truth.
 *
 * INVARIANT (non-negotiable, per issue #1175): a dead/missing/misconfigured
 * broadcaster must never affect any functional flow. Every dispatch site
 * wraps this event in try/catch — see RebuildCatalogRevisionJob.
 *
 * Deliberately NOT `ShouldRescue`, unlike its eight siblings (#1208). It is
 * already protected, and better: the dispatch-site catch logs
 * `workstation_sync_poke_failed` WITH the branch id, which is greppable and
 * tells you which shop stopped being poked. `ShouldRescue` would intercept the
 * throw earlier inside BroadcastManager, so that named warning would never fire
 * and a dead broadcaster would degrade to an anonymous reported exception.
 */
class WorkstationSyncPoke implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly string $branchId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('workstation.sync.'.$this->branchId)];
    }

    public function broadcastAs(): string
    {
        return 'sync.poke';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [];
    }
}
