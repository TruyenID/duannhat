<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Architecture\DomainMutationGuard;
use Illuminate\Console\Command;

final class ReportDomainMutationWriters extends Command
{
    protected $signature = 'architecture:domain-writers {--json : Emit the comparison as JSON}';

    protected $description = 'Report grandfathered aggregate writers and fail on new, stale, expired, or malformed entries';

    public function handle(): int
    {
        $guard = new DomainMutationGuard(config('domain-mutation-guard.aggregates'));
        $allowlistPath = config('domain-mutation-guard.allowlist');
        $allowlist = is_file($allowlistPath) ? require $allowlistPath : [];
        $result = $guard->compare(
            $guard->scan(base_path()),
            $allowlist,
            (int) config('domain-mutation-guard.current_gate', 2),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'known' => array_map(fn ($finding) => $finding->toArray(), $result['known']),
                'new' => array_map(fn ($finding) => $finding->toArray(), $result['new']),
                'stale' => $result['stale'],
                'errors' => $result['errors'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->info(sprintf('Known legacy writers: %d', count($result['known'])));
            foreach ($result['known'] as $finding) {
                $this->line("  {$finding->aggregate} {$finding->path}:{$finding->line} {$finding->kind}:{$finding->symbol} ({$finding->target})");
            }
            foreach ($result['errors'] as $error) {
                $this->components->error($error);
            }
            foreach ($result['new'] as $finding) {
                $this->components->error("NEW {$finding->aggregate} writer {$finding->path}:{$finding->line} {$finding->kind}:{$finding->symbol} ({$finding->target})");
            }
            foreach ($result['stale'] as $key) {
                $this->components->error("STALE allowlist entry {$key}; remove it in the same change as the writer.");
            }
        }

        return $result['new'] === [] && $result['stale'] === [] && $result['errors'] === []
            ? self::SUCCESS
            : self::FAILURE;
    }
}
