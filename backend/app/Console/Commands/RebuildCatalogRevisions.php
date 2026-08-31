<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogRevisionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * #1192 — rebuild every branch's catalog revision at the current snapshot shape.
 *
 * Revisions are normally minted by a catalog edit, so a shape bump (v2 → v3)
 * would otherwise reach a quiet branch only whenever someone next touches its
 * menu. Until then `catalog_revision_has_toppings` reports false and the branch's
 * workstations fall back to the legacy (unsigned) path for topping orders — safe,
 * but it gives up the offline evidence the epic exists for.
 *
 *   php artisan catalog:rebuild-revisions
 *   php artisan catalog:rebuild-revisions --dry-run
 *
 * Idempotent: bumpFor() hash-dedups (BR-CR02), so a branch already on the
 * current shape mints nothing. Runs inline (no queue) — a caller that wants
 * worker parallelism can dispatch the job instead.
 *
 * Scheduled daily (#1255). It used to be a deploy step only, which left a quiet
 * branch stale "until its next catalog edit or a sweep" — and with main sitting
 * 50+ commits behind dev, the next deploy is not a schedule. Inline rather than
 * dispatched on purpose: no queue worker is provisioned in the deploy workflow,
 * so a dispatched sweep would sit in the table unrun, which is the same silence
 * this issue is about.
 */
#[Signature('catalog:rebuild-revisions {--dry-run : Report which branches would mint a new revision without writing.}')]
#[Description('#1192 — mint a current-shape catalog revision for every branch that needs one.')]
class RebuildCatalogRevisions extends Command
{
    public function handle(CatalogRevisionService $revisions): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $branchIds = DB::table('menus')
            ->whereNotNull('branch_id')
            ->whereNull('deleted_at')
            ->distinct()
            ->orderBy('branch_id')
            ->pluck('branch_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($branchIds === []) {
            $this->info('No branch carries a menu — nothing to rebuild.');

            return self::SUCCESS;
        }

        $bumped = 0;
        $unchanged = 0;
        $failed = 0;

        foreach ($branchIds as $branchId) {
            try {
                $current = $revisions->currentFor($branchId);
                $currentVersion = $current === null
                    ? null
                    : (int) (((array) $current->snapshot)['v'] ?? 1);

                if ($dryRun) {
                    $stale = $current === null || $currentVersion < CatalogRevisionService::SNAPSHOT_VERSION;
                    $stale ? $bumped++ : $unchanged++;
                    if ($stale) {
                        $this->line(sprintf(
                            '  would rebuild %s (revision %s, snapshot v%s)',
                            $branchId,
                            $current?->revision ?? '—',
                            $currentVersion ?? '—',
                        ));
                    }

                    continue;
                }

                $after = $revisions->bumpFor($branchId);

                if ($after === null || ($current !== null && $after->revision === $current->revision)) {
                    $unchanged++;

                    continue;
                }

                $bumped++;
                $this->line(sprintf('  %s → revision %d (snapshot v%d)', $branchId, $after->revision, CatalogRevisionService::SNAPSHOT_VERSION));
            } catch (\Throwable $e) {
                $failed++;
                $this->error(sprintf('  %s failed: %s', $branchId, $e->getMessage()));

                // Same tag the job's failed() handler uses, and for the same
                // reason: this branch keeps serving a stale revision, which
                // means stale prices and workstations dropping to the legacy
                // unsigned path for topping orders. Now that the sweep runs on
                // the scheduler rather than by hand, $this->error() reaches a
                // terminal nobody is watching — a branch could fail here every
                // night for months without a sound.
                Log::error('[catalog.revision_stale] catalog_revision_sweep_failed', [
                    'branch_id' => $branchId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            '%s: %d branch(es) %s, %d already current, %d failed.',
            $dryRun ? 'Dry run' : 'Rebuild',
            $bumped,
            $dryRun ? 'would be rebuilt' : 'rebuilt',
            $unchanged,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
