<?php

namespace App\Console\Commands;

use App\Support\Schema\SchemaDiffer;
use App\Support\Schema\SchemaSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * #1216 — does this database still match what the migrations build?
 *
 * A database that has been migrating since before the history was flattened is
 * NOT the database `migrate:fresh` produces. Sixteen tables disagreed the first
 * time anyone looked, and one of them (`role_permissions`, no `id` column while
 * the model declared `$primaryKey = 'id'`) broke Eloquent on the live shape
 * only. Nothing caught it, and nothing could have: tests and CI build fresh, so
 * the fresh shape is the only one they ever see.
 *
 * WHY THIS IS A COMMAND AND NOT A CI TEST. The issue asked for a CI check. CI
 * cannot do it: comparing needs a database that has been migrating for months,
 * and CI has no such thing — it can only build the shape it already agrees
 * with. So the check has to run where the old database lives. Point it at
 * staging or production:
 *
 *     php artisan schema:drift-check
 *     php artisan schema:drift-check --keep --strict
 *
 * It builds a scratch database next to the live one, runs the full migration
 * set into it, compares, then drops the scratch. The live database is only ever
 * READ — every write goes to the scratch schema, and the command refuses
 * outright if the two names collide.
 *
 * RUN `php artisan migrate` FIRST. A database with migrations still pending is
 * behind, not drifted, and every table those migrations would have created
 * reads as `table.missing`. The command counts them and says so up front rather
 * than letting someone chase a difference that one `migrate` would erase.
 *
 * Exit code is 1 when there is drift, so it can be wired into a deploy step.
 * Naming-only differences never fail unless `--strict`; they are real traps
 * (`dropIndex('<name>')` runs on exactly one of the two shapes) but not a
 * reason to block a release.
 */
#[Signature('schema:drift-check
    {--fresh-database= : Name of the scratch database to build (default: <current>_driftcheck)}
    {--keep : Leave the scratch database in place for inspection}
    {--reuse : Do not rebuild the scratch database; compare against what is already there}
    {--strict : Fail on naming-only differences too}
    {--force-fresh : Allow rebuilding a scratch database that already holds tables}')]
#[Description('#1216 — compare this database against the schema the migrations build from scratch.')]
class CheckSchemaDrift extends Command
{
    private const SCRATCH_CONNECTION = 'schema_drift_scratch';

    public function handle(SchemaDiffer $differ): int
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            $this->error('schema:drift-check reads information_schema and only supports MySQL. Current driver: '.$connection->getDriverName());

            return self::FAILURE;
        }

        $liveDatabase = $connection->getDatabaseName();
        $freshDatabase = (string) ($this->option('fresh-database') ?: $liveDatabase.'_driftcheck');

        if ($freshDatabase === $liveDatabase) {
            $this->error('The scratch database must not be the live one — that would drop every table in it.');

            return self::FAILURE;
        }

        $this->line(sprintf('live=%s scratch=%s', $liveDatabase, $freshDatabase));
        $this->warnAboutPendingMigrations();

        $this->configureScratchConnection($connection, $freshDatabase);

        // The name check above compares STRINGS the caller typed. This one
        // compares the database each connection actually resolves to, and it is
        // the check that matters: `migrate:fresh` drops whatever the scratch
        // connection points at, not whatever `--fresh-database` said.
        //
        // They can disagree. `config/database.php` carries `'url' => env('DB_URL')`,
        // and Laravel's ConfigurationUrlParser merges the URL's components OVER
        // the array config — so on any deployment that sets DB_URL (the exact
        // deployment shape this command exists to be pointed at) the scratch
        // connection inherits the live URL, resolves to the live database, and
        // `migrate:fresh --force` empties production. The string check passes
        // happily: 'tempo_driftcheck' !== 'tempo'.
        //
        // Asserting on the resolved name closes that path and every other one
        // like it, including a case-only collision under lower_case_table_names=1.
        $resolvedScratch = DB::connection(self::SCRATCH_CONNECTION)->getDatabaseName();

        if ($resolvedScratch === $liveDatabase) {
            $this->error(sprintf(
                'The scratch connection resolves to `%s`, which is the live database — refusing to run. '
                .'A `url`/DB_URL entry on the `%s` connection overrides the database name; unset it or point '
                .'DB_URL at the scratch database explicitly.',
                $resolvedScratch,
                $connection->getName(),
            ));

            return self::FAILURE;
        }

        // Only a scratch schema this run actually built may be dropped. A run
        // that refused to touch a pre-existing database must not then delete it
        // on the way out.
        //
        // buildScratchSchema() flips this the moment CREATE DATABASE succeeds,
        // not when the whole build does — from that instant the schema is ours
        // and cleaning it up is correct. Setting it only on full success left a
        // half-migrated database behind whenever `migrate:fresh` failed, which
        // the NEXT run then refused to touch ("already holds N tables"),
        // demanding --force-fresh to clear a mess this command made itself.
        $built = false;

        try {
            if (! (bool) $this->option('reuse')) {
                if (! $this->buildScratchSchema($connection, $freshDatabase, $built)) {
                    return self::FAILURE;
                }
            }

            try {
                $expected = SchemaSnapshot::read(DB::connection(self::SCRATCH_CONNECTION), $freshDatabase);
            } catch (Throwable $e) {
                $this->error(sprintf('could not read `%s`: %s', $freshDatabase, $e->getMessage()));

                return self::FAILURE;
            }

            if ($expected->tables === []) {
                $this->error(sprintf('`%s` holds no tables — there is nothing to compare against. Drop --reuse to build it.', $freshDatabase));

                return self::FAILURE;
            }

            $actual = SchemaSnapshot::read($connection, $liveDatabase);
        } finally {
            if ($built && ! (bool) $this->option('keep')) {
                $this->dropScratchSchema($connection, $freshDatabase);
            }
        }

        return $this->report($differ->compare($expected->tables, $actual->tables));
    }

    /**
     * A database that is merely BEHIND looks exactly like one that has drifted:
     * the tables its pending migrations would create are simply not there. Say
     * so before printing a report someone might act on.
     */
    private function warnAboutPendingMigrations(): void
    {
        $migrator = app('migrator');

        try {
            $ran = $migrator->getRepository()->getRan();
        } catch (Throwable) {
            $this->warn('could not read the `migrations` table — treating every difference as drift');

            return;
        }

        // `paths()` holds only what a provider registered (here: the omnify
        // directory). The default `database/migrations` is added by
        // MigrateCommand, not by the migrator, so it has to be merged in — miss
        // it and every hand-written migration reads as pending.
        $paths = array_merge($migrator->paths(), [database_path('migrations')]);

        $pending = array_values(array_diff(
            array_keys($migrator->getMigrationFiles($paths)),
            $ran,
        ));

        if ($pending === []) {
            return;
        }

        $this->warn(sprintf(
            '%d migration(s) have not run on the live database. Run `php artisan migrate` first — until then some of what follows is pending work, not drift:',
            count($pending),
        ));
        foreach (array_slice($pending, 0, 10) as $migration) {
            $this->line('  '.$migration);
        }
        if (count($pending) > 10) {
            $this->line(sprintf('  … and %d more', count($pending) - 10));
        }
        $this->newLine();
    }

    /**
     * Clone the live connection, pointed at the scratch database.
     *
     * The config is resolved through ConfigurationUrlParser FIRST, then
     * `database` is overwritten. Order matters and is the whole point: that
     * parser merges a `url`/DB_URL's components OVER the array config, so
     * setting `database` and leaving `url` in place hands `migrate:fresh` the
     * LIVE database under a scratch name. Resolving first flattens the URL into
     * ordinary host/port/username/password/database keys and removes `url`
     * entirely, so the override below is the last word — while every
     * credential the URL carried still comes through, which simply dropping
     * `url` would have thrown away on a URL-only deployment.
     *
     * handle() re-asserts on the resolved name afterwards. This removes the
     * cause; the assertion catches anything else that could point the two
     * connections at one database.
     */
    private function configureScratchConnection(Connection $connection, string $database): void
    {
        Config::set(
            'database.connections.'.self::SCRATCH_CONNECTION,
            self::scratchConnectionConfig(Config::get('database.connections.'.$connection->getName()), $database),
        );
        DB::purge(self::SCRATCH_CONNECTION);
    }

    /**
     * The config-building step on its own, so the guard above can be tested
     * without a MySQL server and without running `migrate:fresh` — the command
     * whose misfire is the whole risk.
     *
     * @param  array<string, mixed>  $liveConfig
     * @return array<string, mixed>
     */
    public static function scratchConnectionConfig(array $liveConfig, string $database): array
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration($liveConfig);

        $config['database'] = $database;

        return $config;
    }

    /**
     * @param  bool  $built  set to true as soon as this run owns the schema
     */
    private function buildScratchSchema(Connection $connection, string $database, bool &$built): bool
    {
        $existingTables = (int) $connection->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$database]
        )->n;

        if ($existingTables > 0 && ! (bool) $this->option('force-fresh')) {
            $this->error(sprintf(
                '`%s` already holds %d tables. Refusing to drop them — pass --force-fresh if that database really is scratch, or --fresh-database=<other>.',
                $database,
                $existingTables,
            ));

            return false;
        }

        $connection->statement(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
            $database,
            Config::get('database.connections.'.$connection->getName().'.charset', 'utf8mb4'),
            Config::get('database.connections.'.$connection->getName().'.collation', 'utf8mb4_unicode_ci'),
        ));

        // From here on the schema is this run's to clean up, however the build
        // ends.
        $built = true;

        $this->line('building the scratch schema (migrate:fresh) — this takes a minute');

        $exitCode = Artisan::call('migrate:fresh', [
            '--database' => self::SCRATCH_CONNECTION,
            '--force' => true,
        ], $this->getOutput()->isVerbose() ? $this->output : null);

        if ($exitCode !== 0) {
            $this->error('migrate:fresh failed against the scratch database — the migration set itself is broken, which is a bigger problem than drift.');

            return false;
        }

        return true;
    }

    private function dropScratchSchema(Connection $connection, string $database): void
    {
        try {
            $connection->statement(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
        } catch (Throwable $e) {
            $this->warn(sprintf('could not drop the scratch database `%s`: %s', $database, $e->getMessage()));
        }
    }

    /**
     * @param  list<array{table: string, severity: string, kind: string, detail: string}>  $findings
     */
    private function report(array $findings): int
    {
        $drift = array_values(array_filter($findings, fn (array $f): bool => $f['severity'] === SchemaDiffer::DRIFT));
        $cosmetic = array_values(array_filter($findings, fn (array $f): bool => $f['severity'] === SchemaDiffer::COSMETIC));

        if ($findings === []) {
            $this->info('No drift — this database matches what the migrations build.');

            return self::SUCCESS;
        }

        if ($drift !== []) {
            $this->newLine();
            $this->error(sprintf('%d behavioural difference(s):', count($drift)));
            foreach ($drift as $f) {
                $this->line(sprintf('  %-40s %-22s %s', $f['table'], $f['kind'], $f['detail']));
            }
        }

        if ($cosmetic !== []) {
            $this->newLine();
            $this->warn(sprintf('%d naming-only difference(s) — same constraint, different name:', count($cosmetic)));
            foreach ($cosmetic as $f) {
                $this->line(sprintf('  %-40s %-22s %s', $f['table'], $f['kind'], $f['detail']));
            }
        }

        $this->newLine();
        $this->line(sprintf('%d table(s) affected.', count(array_unique(array_column($findings, 'table')))));

        if ($drift !== []) {
            return self::FAILURE;
        }

        return (bool) $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
