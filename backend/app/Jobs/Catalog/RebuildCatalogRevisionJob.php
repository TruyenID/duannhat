<?php

namespace App\Jobs\Catalog;

use App\Events\WorkstationSyncPoke;
use App\Services\Catalog\CatalogRevisionService;
use App\Services\Workstation\SyncManifestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Rebuilds one branch's catalog revision in the background (#1174, option C).
 *
 * CatalogRevisionService::flushDirty() used to call bumpFor() inline in the
 * HTTP request's afterCommit hook — a topping price edit touching many
 * branches rebuilt every affected snapshot synchronously (~10s → FE signal
 * timeout). flushDirty() now only dispatches ONE of these per deduped branch;
 * the snapshot work happens on a worker.
 *
 * Idempotent and safe to run repeatedly: bumpFor() hash-dedups (BR-CR02), so
 * a rerun over an unchanged price map mints nothing. ShouldBeUnique keyed by
 * branch id (uniqueFor 10s) debounces rapid successive HQ edits into one
 * rebuild without ever suppressing a later change for long — the next edit
 * after the window re-dispatches, and a rebuild always reads current state.
 *
 * Retries (#1192): the original $tries = 1 swallowed every failure, so one
 * deadlock or lock timeout meant the branch kept serving its OLD revision until
 * somebody happened to edit its catalog again — hours or days, not the "few
 * seconds of staleness" #1174 signed up for, and offline orders signed in that
 * window verify against a stale price map. Three attempts with backoff cover
 * the transient DB faults that make up nearly all of these; a genuinely broken
 * branch still ends in failed() with the same loud log.
 *
 * After running, the job always fires the #1175 poke for the branch (pokes
 * are free, contentless hints) and drops the branch's cached sync manifest so
 * the poked pull sees fresh feed versions. INVARIANT: a dead/missing
 * broadcaster must never affect the job result — the poke is try/catch
 * swallowed + logged.
 */
final class RebuildCatalogRevisionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds to wait before retry 2 and retry 3. */
    public array $backoff = [5, 30];

    /** Debounce window (seconds) for rapid successive edits to one branch. */
    public int $uniqueFor = 10;

    public function __construct(public readonly string $branchId) {}

    public function uniqueId(): string
    {
        return $this->branchId;
    }

    public function handle(CatalogRevisionService $revisions): void
    {
        // Rethrown on purpose: the queue owns the retry. Swallowing here is
        // what made a transient fault permanent (#1192).
        try {
            $revisions->bumpFor($this->branchId);
        } catch (\Throwable $e) {
            Log::warning('catalog_revision_rebuild_attempt_failed', [
                'branch_id' => $this->branchId,
                'attempt' => $this->attempts(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        try {
            SyncManifestService::forget($this->branchId);
        } catch (\Throwable $e) {
            Log::warning('sync_manifest_cache_forget_failed', [
                'branch_id' => $this->branchId,
                'message' => $e->getMessage(),
            ]);
        }

        // #1175 poke — fired unconditionally: the dirty mark that queued this
        // job means something catalog-shaped changed, and a spurious poke only
        // costs the workstation one manifest GET (usually a 304).
        try {
            WorkstationSyncPoke::dispatch($this->branchId);
        } catch (\Throwable $e) {
            Log::warning('workstation_sync_poke_failed', [
                'branch_id' => $this->branchId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Every attempt is spent. The branch is now serving a stale revision until
     * its next catalog edit or a `catalog:rebuild-revisions` sweep, so this is
     * the log line to alert on.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('[catalog.revision_stale] catalog_revision_rebuild_failed', [
            'branch_id' => $this->branchId,
            'attempts' => $this->tries,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
