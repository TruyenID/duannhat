<?php

declare(strict_types=1);

namespace App\Console\Commands\Deploy;

use App\Services\Workstation\WorkstationDownloadCatalog;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Fail the deploy when the expected-build feed cannot tell a shop machine
 * anything useful (#3173).
 *
 * THE FAILURE THIS CATCHES, and it is not hypothetical. `WORKSTATION_EXPECTED_VERSION`
 * was **never set** on production. `ExpectedBuildController` gates every field
 * on `$version === null`, so `GET /api/v1/workstation/expected-build` answered
 * `version: null` — and `severity`, `reason` and `package` with it. Machines
 * asked, Cloud said "nothing", machines stood still. Measured 2026-08-17: three
 * shop machines on `v0.6.0` while `v0.8.26` was published; the self-update code
 * had been shipped since 2026-08-10 and simply had nothing to act on.
 *
 * Nothing went red, because nothing was wrong — it was only never armed. That
 * is the same shape as #2451, where a fix sat in a list nobody read and
 * production kept reporting zero. A mechanism whose failure mode is silence
 * needs something that breaks the silence.
 *
 * WHY IT CANNOT BE A TEST. The suite runs against the test environment; the
 * value that matters lives in the `.env` of the machine just deployed to, next
 * to the manifest that machine will actually serve. Only a deploy-time check
 * stands where both are visible at once.
 *
 * THE TWO FAILURES ARE REPORTED SEPARATELY on purpose, because they send an
 * operator to different files:
 *
 *   - unset while builds exist → the `.env` key is missing
 *   - set to a version absent from the manifest → the manifest, or a typo in
 *     the version string; the feed would name a build nobody can download
 *
 * The second one is easy to create by hand and impossible to see from outside:
 * the endpoint keeps answering 200 with a version and a null package.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not pick a version. "Which build
 * should shops be on" is a decision #2635 gave to HQ — that is the whole point
 * of `auto_apply` travelling per-build — and a deploy that silently advanced it
 * to whatever shipped last would take away the ability to hold a bad build
 * back. This check only refuses to let the question go unanswered.
 *
 * An empty manifest is NOT a failure: a fresh install with nothing published
 * yet has nothing to expect, and failing there would block the very first
 * deploy.
 */
final class VerifyWorkstationExpectedVersionCommand extends Command
{
    protected $signature = 'deploy:verify-workstation-expected-version';

    protected $description = 'Assert the workstation expected-build feed names a version shops can actually download';

    public function handle(WorkstationDownloadCatalog $catalog): int
    {
        $expected = trim((string) config('workstation.expected_build.version'));
        $published = $this->publishedVersions($catalog);

        if ($published === []) {
            $this->info('workstation manifest lists no builds — nothing to expect yet, skipping.');

            return self::SUCCESS;
        }

        if ($expected === '') {
            throw new RuntimeException(sprintf(
                'WORKSTATION_EXPECTED_VERSION is EMPTY while the manifest publishes %d build(s) '
                .'(newest: %s). The expected-build feed answers version:null, so every shop machine '
                .'is told nothing and never updates — silently, forever. Set the key in .env and '
                .'run config:cache. Pick the version deliberately; the deploy will not pick for you.',
                count($published),
                $published[0],
            ));
        }

        if (! in_array($expected, $published, true)) {
            throw new RuntimeException(sprintf(
                'WORKSTATION_EXPECTED_VERSION = [%s] is not in the workstation manifest, so the feed '
                .'would name a build with no download package. Published: %s. '
                .'Note the comparison is an EXACT string match and the manifest carries the "v" prefix.',
                $expected,
                implode(', ', array_slice($published, 0, 5)),
            ));
        }

        $this->info(sprintf('WORKSTATION_EXPECTED_VERSION = [%s] — published and downloadable.', $expected));

        return self::SUCCESS;
    }

    /**
     * Newest first, archives included — a shop held back on an older build is a
     * legitimate state, so an archived version must not read as a typo.
     *
     * @return list<string>
     */
    private function publishedVersions(WorkstationDownloadCatalog $catalog): array
    {
        $catalogue = $catalog->read();
        $versions = [];

        // Same two lists `packageForVersion()` walks, in the same order, so a
        // version this check accepts is exactly a version the feed can resolve.
        foreach (array_merge($catalogue['versions'], $catalogue['archive_versions']) as $entry) {
            $version = $entry['version'] ?? null;
            if (is_string($version) && $version !== '') {
                $versions[] = $version;
            }
        }

        return array_values(array_unique($versions));
    }
}
