<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Print\SystemTemplateDefaults;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * #1181 — export LAYER 0 (Cloud's system default definitions) as JSON so the
 * workstation repo can render them with the REAL Go renderer and compare the
 * bytes against `internal/service/testdata/print_golden.json`.
 *
 * Why an export rather than a shared file: layer 0 is COMPOSED in PHP from two
 * config files (`print_blocks.php` supplies block ORDER, `print_templates.php`
 * supplies per-block props), so there is no single artefact to diff by eye. The
 * export is that artefact, and committing it into the workstation's `testdata/`
 * turns "Cloud and Go agree" into a test rather than a claim.
 *
 * The output is byte-stable (kinds in enum order, `JSON_PRETTY_PRINT`,
 * unescaped unicode + slashes) so re-running it on an unchanged catalog
 * produces an unchanged file and the diff is always meaningful.
 *
 *   php artisan print-templates:export-defaults \
 *     --out=../workstation/internal/service/testdata/cloud_print_templates_default.json
 */
#[Signature('print-templates:export-defaults {--out= : File to write (default: stdout)} {--kind=* : Limit to these kinds}')]
#[Description('Export the print-template system defaults (layer 0) as JSON — the Go↔Cloud parity fixture (#1181)')]
class ExportPrintTemplateDefaults extends Command
{
    public function handle(SystemTemplateDefaults $defaults): int
    {
        $all = $defaults->all();

        /** @var list<string> $only */
        $only = (array) $this->option('kind');
        if ($only !== []) {
            $unknown = array_diff($only, array_keys($all));
            if ($unknown !== []) {
                $this->error('Unknown kind(s): '.implode(', ', $unknown));

                return self::FAILURE;
            }
            $all = array_intersect_key($all, array_flip($only));
        }

        $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->error('Failed to encode definitions: '.json_last_error_msg());

            return self::FAILURE;
        }
        $json .= "\n";

        $out = $this->option('out');
        if (! is_string($out) || trim($out) === '') {
            $this->line($json);

            return self::SUCCESS;
        }

        $dir = dirname($out);
        if (! is_dir($dir)) {
            $this->error("Directory does not exist: {$dir}");

            return self::FAILURE;
        }

        if (file_put_contents($out, $json) === false) {
            $this->error("Could not write {$out}");

            return self::FAILURE;
        }

        $this->info(sprintf('Wrote %d kind(s) to %s', count($all), $out));

        return self::SUCCESS;
    }
}
