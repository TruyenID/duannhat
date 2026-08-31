<?php

declare(strict_types=1);

namespace App\Console\Commands\Deploy;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Write Tempo's authorization catalog to a file for Platform to ingest (#2463).
 *
 * The deploy then hands the file to Platform's own
 * `service:sync-authz-manifest tempo <path>`. Tempo declares what permissions
 * exist; Platform is the system of record for who holds them.
 *
 * THE EMPTY-CATALOG CHECK IS PART OF THE EXPORT, not a step after it. Syncing
 * an empty manifest is not a no-op on the Platform side — it publishes "Tempo
 * has no permissions", and the next login resolves every user down to nothing.
 * The inline version verified this AFTER the sync had already run, which is one
 * step too late to help. Checking here means the bad manifest never leaves.
 *
 * `var_export` rather than JSON: Platform's importer requires a PHP file it can
 * `require`, matching what `config/authz.php` already is.
 */
final class ExportAuthzManifestCommand extends Command
{
    protected $signature = 'deploy:export-authz-manifest {path : Where to write the PHP manifest}';

    protected $description = 'Export config/authz.php for Platform, refusing to emit an empty permission catalog';

    public function handle(): int
    {
        $manifest = require config_path('authz.php');

        if (! is_array($manifest)) {
            throw new RuntimeException('config/authz.php did not return an array.');
        }

        $permissions = $manifest['permissions'] ?? [];

        if ($permissions === []) {
            throw new RuntimeException(
                'Tempo permission catalog is EMPTY — refusing to export. Syncing this into Platform would '
                .'publish "Tempo has no permissions" and strip every user on their next login.'
            );
        }

        $path = (string) $this->argument('path');

        if (file_put_contents($path, '<?php return '.var_export($manifest, true).';') === false) {
            throw new RuntimeException(sprintf('Could not write the manifest to [%s].', $path));
        }

        $this->info(sprintf('Tempo permission catalog: %d permissions → %s', count($permissions), $path));

        return self::SUCCESS;
    }
}
