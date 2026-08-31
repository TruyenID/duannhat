<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Plan-022 T1.7 — pre-deploy guard.
 *
 * After T1, every material_lots row must store qty_on_hand / received_qty in
 * the material's base unit. Prior code wrote the operator-entered unit (e.g.
 * "2 bag_25kg") which silently corrupts stock_levels (= SUM(qty_on_hand)).
 *
 * NOTES.md audit (2026-05-12) confirms the DB is empty on the rolling-out
 * environment, so this command exists as a safety net for staging / prod that
 * may have been seeded between audit and deploy. CI / deploy pipelines should
 * run it AFTER `migrate` AND BEFORE switching traffic to the T1 build.
 *
 *   docker compose exec -T app php artisan material-lots:assert-base-unit
 *
 * Exit code 1 means a backfill migration is required before the new pipeline
 * is safe; the deploy must abort.
 */
#[Signature('material-lots:assert-base-unit')]
#[Description('Plan-022 T1.7 — fail the deploy when material_lots holds rows in a non-base unit.')]
class AssertLotsBaseUnitInvariant extends Command
{
    public function handle(): int
    {
        $violations = DB::table('material_lots as ml')
            ->join('material_units as mu', function ($join) {
                $join->on('mu.material_id', '=', 'ml.material_id')
                    ->where('mu.is_base', '=', true);
            })
            ->whereColumn('ml.unit', '!=', 'mu.unit')
            ->whereNotNull('ml.unit')
            ->whereNull('ml.deleted_at')
            ->count();

        if ($violations > 0) {
            $this->error(sprintf(
                'Found %d material_lots in non-base unit. Backfill required '
                .'before promoting the T1 build — see plan-022 NOTES.md.',
                $violations
            ));

            return self::FAILURE;
        }

        $this->info('material_lots base-unit invariant holds.');

        return self::SUCCESS;
    }
}
