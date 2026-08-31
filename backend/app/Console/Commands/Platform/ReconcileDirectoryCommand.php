<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Models\Organization;
use App\Models\User;
use App\Services\Platform\DirectoryReconciler;
use Dxs\Auth\Services\PlatformContextClient;
use Dxs\Auth\Services\TokenRefresher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Compares Tempo's directory mirror against Platform and reports drift
 * (#3143 — ADR 0002, layer 3).
 *
 * ## Why layer 3 exists at all
 *
 * Layer 1 (the observers on Platform) can be wrong in complete silence — a
 * write path nobody hooked produces no event, no row, and no error. Layer 2 (the
 * arch guards) only proves the two KNOWN ways that happens are absent. This is
 * the layer that notices the unknown third way, which is why ADR 0002 puts it
 * BEFORE the transport: it is the measurement that proves the relay works.
 *
 * ## THREE outcomes, and the third is the point
 *
 *   ok          compared, no drift
 *   drift       compared, differences listed
 *   unverified  could NOT compare
 *
 * `unverified` must never be counted as `ok`. It happens for a mundane reason:
 * `PlatformContextClient` needs a USER access token — `SSO_ADMIN_TOKEN` is
 * documented as coming "from CI or an operator, not the app runtime" — so this
 * command has to borrow a stored token from a member of the organization. An org
 * whose members have no usable token cannot be checked at all.
 *
 * Folding that into "0 drift" would rebuild exactly the hole this command exists
 * to close: a zero that cannot distinguish "nothing differs" from "nothing was
 * asked".
 *
 * ## READ ONLY
 *
 * No repair, no re-stamp, no backfill. Repairing here would paper over the
 * missing write path this is meant to reveal, on live tenant data.
 */
final class ReconcileDirectoryCommand extends Command
{
    protected $signature = 'platform:reconcile-directory
        {--org= : Limit to one organization slug}
        {--json : Machine-readable output}
        {--strict : Exit non-zero when drift is found}';

    protected $description = "Compare Tempo's mirror of the Platform directory and report drift (read only)";

    public function handle(
        PlatformContextClient $contexts,
        TokenRefresher $refresher,
        DirectoryReconciler $reconciler,
    ): int {
        $organizations = Organization::query()
            ->when($this->option('org'), fn ($query, $slug) => $query->where('slug', $slug))
            ->get();

        if ($organizations->isEmpty()) {
            // Distinguish "asked about nothing" from "nothing is wrong" here too:
            // an empty subject list is the third state at the outer level.
            $this->warn('No organizations matched — nothing was compared.');

            return self::FAILURE;
        }

        $report = [];

        foreach ($organizations as $organization) {
            $report[] = $this->reconcile($organization, $contexts, $refresher, $reconciler);
        }

        return $this->render($report);
    }

    /**
     * @return array{organization: string, status: string, reason?: string, drift: list<array<string, mixed>>}
     */
    private function reconcile(
        Organization $organization,
        PlatformContextClient $contexts,
        TokenRefresher $refresher,
        DirectoryReconciler $reconciler,
    ): array {
        $token = $this->borrowToken($organization, $refresher);

        if ($token === null) {
            return [
                'organization' => (string) $organization->slug,
                'status' => 'unverified',
                'reason' => 'no member of this organization has a usable Platform token',
                'drift' => [],
            ];
        }

        try {
            $remoteOrganizations = $contexts->organizations($token);
            $context = collect($remoteOrganizations)->first(
                fn (array $row): bool => (string) ($row['organization_id'] ?? '') === (string) $organization->console_organization_id,
            );

            if (! is_array($context)) {
                return [
                    'organization' => (string) $organization->slug,
                    'status' => 'unverified',
                    'reason' => 'Platform did not return this organization for the borrowed token',
                    'drift' => [],
                ];
            }

            $brands = $contexts->brands($token, (string) $organization->slug)['brands'] ?? [];
            $branches = $contexts->branches($token, (string) $organization->slug)['branches'] ?? [];

            $drift = $reconciler->compare(
                $organization,
                $context,
                is_array($brands) ? array_values(array_filter($brands, 'is_array')) : [],
                is_array($branches) ? array_values(array_filter($branches, 'is_array')) : [],
            );

            return [
                'organization' => (string) $organization->slug,
                'status' => $drift === [] ? 'ok' : 'drift',
                'drift' => $drift,
            ];
        } catch (Throwable $exception) {
            // Never swallowed into `ok`. `UserProvisioner` turning every failure
            // into a `Log::warning` is precisely why mirror rot was invisible for
            // so long; a reconciliation tool repeating that would be worse than
            // not existing.
            return [
                'organization' => (string) $organization->slug,
                'status' => 'unverified',
                'reason' => $exception::class.': '.$exception->getMessage(),
                'drift' => [],
            ];
        }
    }

    /**
     * A stored Platform token belonging to a member of this organization.
     *
     * Refreshed before use, because a stale bearer would surface as an HTTP 401
     * that reads like a Platform outage rather than an expired token.
     */
    private function borrowToken(Organization $organization, TokenRefresher $refresher): ?string
    {
        $users = User::query()
            ->where('console_organization_id', $organization->console_organization_id)
            ->whereNotNull('console_access_token')
            ->orderByDesc('console_token_expires_at')
            ->limit(5)
            ->get();

        foreach ($users as $user) {
            try {
                $refresher->ensureFresh($user);
            } catch (Throwable) {
                // Try the next member rather than declaring the org unverifiable
                // on one bad token.
                continue;
            }

            $token = $user->console_access_token;

            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        return null;
    }

    /**
     * @param  list<array{organization: string, status: string, reason?: string, drift: list<array<string, mixed>>}>  $report
     */
    private function render(array $report): int
    {
        $drifted = array_values(array_filter($report, fn (array $row): bool => $row['status'] === 'drift'));
        $unverified = array_values(array_filter($report, fn (array $row): bool => $row['status'] === 'unverified'));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'organizations' => count($report),
                'ok' => count($report) - count($drifted) - count($unverified),
                'drift' => count($drifted),
                'unverified' => count($unverified),
                'report' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($report as $row) {
                match ($row['status']) {
                    'ok' => $this->info("✓ {$row['organization']} — no drift"),
                    'drift' => $this->warn("! {$row['organization']} — ".count($row['drift']).' difference(s)'),
                    default => $this->error("? {$row['organization']} — UNVERIFIED: ".($row['reason'] ?? 'unknown')),
                };

                foreach ($row['drift'] as $item) {
                    $this->line(sprintf(
                        '    %s %s.%s  local=%s  platform=%s',
                        $item['entity'],
                        $item['id'],
                        $item['field'],
                        var_export($item['local'], true),
                        var_export($item['remote'], true),
                    ));
                }
            }

            // Printed even at zero drift: the count of things NOT checked is the
            // number a reader needs in order to know what the zero is worth.
            $this->newLine();
            $this->line(sprintf(
                'organizations=%d  ok=%d  drift=%d  unverified=%d',
                count($report),
                count($report) - count($drifted) - count($unverified),
                count($drifted),
                count($unverified),
            ));
        }

        // `unverified` alone does not fail the run — an org nobody has logged into
        // is normal — but it is always reported. Drift under --strict fails, so
        // cron and CI have a signal that means something.
        return ($drifted !== [] && $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }
}
