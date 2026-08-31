<?php

declare(strict_types=1);

namespace App\Console\Commands\Deploy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mark an omnify `create_*_table` migration as run when its table already
 * exists (#2463).
 *
 * WHY THIS EXISTS. Omnify renumbers its migrations whenever the generator
 * reorders them (see the 6.0.1 note in the `omnify-regen` skill: 34 files were
 * renumbered with byte-identical contents). Production has the TABLES but its
 * `migrations` rows carry the OLD filenames, so `migrate --force` would try to
 * create tables that are already there and abort the deploy.
 *
 * WHY IT IS A COMMAND AND NOT INLINE YAML. It used to be a PHP heredoc piped
 * into `artisan tinker --execute` inside `deploy-xserver.yml` — code that
 * cannot be linted, reviewed as code, or tested, sitting on the path that
 * writes to the production database on every push to `main`. Two real service
 * incidents on 2026-08-11 came from that path. Moving it here is what puts it
 * within reach of CI.
 *
 * BEHAVIOUR IS UNCHANGED FROM THE INLINE VERSION, deliberately — this is a
 * relocation, not a redesign. Same guard (`create_(.+)_table` AND the table
 * actually exists), same single new batch number, same skip of anything already
 * recorded. Only omnify migrations are considered: `database/migrations/omnify/`
 * is generated output, and a hand-written migration that has not run is a real
 * pending migration, never something to paper over.
 */
final class ReconcileOmnifyMigrationsCommand extends Command
{
    protected $signature = 'deploy:reconcile-omnify-migrations {--dry-run : Report what would be reconciled and write nothing}';

    protected $description = 'Record omnify create-table migrations whose table already exists, so migrate --force can proceed';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $alreadyRun = DB::table('migrations')->pluck('migration')->all();
        $files = glob(database_path('migrations/omnify/*.php')) ?: [];

        // ONE batch for the whole reconciliation, read before the first insert:
        // these rows describe a single deploy-time repair, and spreading them
        // across batches would make `migrate:rollback` unpick them one by one.
        $batch = ((int) DB::table('migrations')->max('batch')) + 1;

        $reconciled = [];

        foreach ($files as $file) {
            $migration = basename($file, '.php');

            if (in_array($migration, $alreadyRun, true)) {
                continue;
            }

            // Only a CREATE whose table is already present. An `alter_*`
            // migration that has not run has real work left to do, and
            // recording it would silently skip a schema change.
            if (! preg_match('/create_(.+)_table/', $migration, $matches)) {
                continue;
            }

            if (! Schema::hasTable($matches[1])) {
                continue;
            }

            if (! $dryRun) {
                DB::table('migrations')->insert(['migration' => $migration, 'batch' => $batch]);
            }

            $reconciled[] = $migration;
            $this->line(($dryRun ? 'Would reconcile: ' : 'Reconciled: ').$migration);
        }

        $this->info(sprintf(
            '%s %d omnify migration(s)%s.',
            $dryRun ? 'Would reconcile' : 'Reconciled',
            count($reconciled),
            $dryRun ? ' (dry run — nothing written)' : '',
        ));

        return self::SUCCESS;
    }
}
