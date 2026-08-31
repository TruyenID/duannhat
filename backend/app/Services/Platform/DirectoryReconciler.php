<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Compares Tempo's mirror of the Platform directory against what Platform says
 * (#3143 — ADR 0002, lớp 3).
 *
 * ## Why this class holds no HTTP and no credentials
 *
 * The comparison is the part worth testing exhaustively, and the part that must
 * stay correct when the transport changes. It reads local rows from the database
 * but takes the remote side as plain arrays, so every drift shape below has a
 * test that needs neither a token nor a reachable Platform — which matters,
 * because the one thing a reconciliation tool must never do is report "no drift"
 * because it silently failed to ask.
 *
 * Credential handling and the three-state verdict live in
 * `ReconcileDirectoryCommand`, deliberately away from the comparison.
 *
 * ## READ ONLY
 *
 * Nothing here writes. Detecting drift is this class's job; deciding what to do
 * about it is a person's. A sweep that repairs what it finds would paper over
 * the missing write path it exists to reveal — and would do it on live tenant
 * data.
 */
final class DirectoryReconciler
{
    /**
     * Fields compared per entity. Exactly what `App\Sso\UserProvisioner` mirrors
     * — the point is to catch the mirror drifting from its source, so comparing
     * anything it does not write would report drift nobody can fix, and skipping
     * something it does write would hide the real thing.
     *
     * Local column => remote payload key.
     */
    private const ORGANIZATION_FIELDS = [
        'name' => 'organization_name',
        'slug' => 'organization_slug',
        'operating_country' => 'country',
    ];

    private const BRAND_FIELDS = [
        'slug' => 'brand_slug',
        'name' => 'brand_name',
        'description' => 'description',
        'logo_url' => 'logo_url',
        'is_active' => 'is_active',
    ];

    /**
     * `is_active` is deliberately ABSENT here (#3161).
     *
     * An earlier version of this note said "the column is not a mirror of
     * anything". True, but it understated the reason and invited someone to go
     * fix the provisioner. The measured reason:
     *
     * Platform's `/api/sso/branches` FILTERS to active branches
     * (`SsoBranchController` → `->where('is_active', true)`) and sends no
     * `is_active` key. So for this feed, activeness is implied by PRESENCE —
     * there is no value to compare, and inventing one from absence would be the
     * "missing means deleted" antipattern that SCIM 2.0 (RFC 7643 §4.1.1)
     * exists to rule out.
     *
     * The gap is not ignored, it is reported DIFFERENTLY: a branch deactivated
     * upstream stops appearing, and `compareCollection()` already flags that as
     * `local present / remote absent`. That is the honest shape of the signal —
     * "Platform no longer offers this" — rather than a fabricated field
     * difference.
     *
     * Add `is_active` here only after the identity event feed
     * (dxs-platform/platform#798) carries it and the provisioner mirrors it; the
     * required order is in `UserProvisioner::syncBranches()`.
     */
    private const BRANCH_FIELDS = [
        'console_brand_id' => 'brand_id',
        'code' => 'code',
        'slug' => 'slug',
        'name' => 'name',
        'is_headquarters' => 'is_headquarters',
        'timezone' => 'timezone',
        'currency' => 'currency',
        'locale' => 'locale',
    ];

    /**
     * Remote keys whose values are booleans on one side and driver-dependent
     * `1`/`0`/`"1"` on the other.
     *
     * Declared rather than sniffed: casting *any* 0/1 to bool would make a
     * genuine string field of `"1"` compare equal to a remote `true` — a false
     * MATCH, which is the one error a drift detector must never make.
     */
    private const BOOLEAN_KEYS = ['is_active', 'is_headquarters'];

    /**
     * @param  array<string, mixed>  $remoteOrganization  one entry of `/api/sso/organizations`
     * @param  list<array<string, mixed>>  $remoteBrands  `brands` from `/api/sso/brands`
     * @param  list<array<string, mixed>>  $remoteBranches  `branches` from `/api/sso/branches`
     * @return list<array{entity: string, id: string, field: string, local: mixed, remote: mixed}>
     */
    public function compare(
        Organization $organization,
        array $remoteOrganization,
        array $remoteBrands,
        array $remoteBranches,
    ): array {
        return [
            ...$this->compareFields('organization', (string) $organization->console_organization_id, $organization->getAttributes(), $remoteOrganization, self::ORGANIZATION_FIELDS),
            // Queried here rather than through a relation: `App\Models\Organization`
            // declares none, and adding relations to a generated model to satisfy a
            // read-only report would be a wider change than the report deserves.
            ...$this->compareCollection(
                'brand',
                Brand::query()
                    ->where('console_organization_id', $organization->console_organization_id)
                    ->get()
                    ->keyBy(fn (Brand $brand): string => (string) $brand->console_brand_id),
                $this->keyRemote($remoteBrands, 'brand_id'),
                self::BRAND_FIELDS,
            ),
            ...$this->compareCollection(
                'branch',
                Branch::query()
                    ->where('console_organization_id', $organization->console_organization_id)
                    ->get()
                    ->keyBy(fn (Branch $branch): string => (string) $branch->console_branch_id),
                $this->keyRemote($remoteBranches, 'id'),
                self::BRANCH_FIELDS,
            ),
        ];
    }

    /**
     * @param  Collection<string, Brand|Branch>  $local
     * @param  array<string, array<string, mixed>>  $remote
     * @param  array<string, string>  $fields
     * @return list<array{entity: string, id: string, field: string, local: mixed, remote: mixed}>
     */
    private function compareCollection(string $entity, Collection $local, array $remote, array $fields): array
    {
        $drift = [];

        foreach ($remote as $id => $remoteRow) {
            $localRow = $local->get($id);

            if ($localRow === null) {
                // Present upstream, absent here. This is the shape a missed
                // write path produces, and the reason the sweep exists.
                $drift[] = ['entity' => $entity, 'id' => $id, 'field' => '*', 'local' => null, 'remote' => 'present'];

                continue;
            }

            $drift = [...$drift, ...$this->compareFields($entity, $id, $localRow->getAttributes(), $remoteRow, $fields)];
        }

        foreach ($local->keys() as $id) {
            if (! array_key_exists((string) $id, $remote)) {
                // Here but not upstream. Not automatically a bug — Platform may
                // scope what it returns — so it is reported as its own shape
                // rather than folded in with the case above.
                $drift[] = ['entity' => $entity, 'id' => (string) $id, 'field' => '*', 'local' => 'present', 'remote' => null];
            }
        }

        return $drift;
    }

    /**
     * @param  array<string, mixed>  $localAttributes
     * @param  array<string, mixed>  $remoteRow
     * @param  array<string, string>  $fields
     * @return list<array{entity: string, id: string, field: string, local: mixed, remote: mixed}>
     */
    private function compareFields(string $entity, string $id, array $localAttributes, array $remoteRow, array $fields): array
    {
        $drift = [];

        foreach ($fields as $localColumn => $remoteKey) {
            // A key Platform did not send is NOT drift. `UserProvisioner` adopts
            // several fields only when present (an older Platform without
            // `country` must not reset an already-mirrored value), so demanding
            // them here would report drift for a mirror that is behaving exactly
            // as designed.
            if (! array_key_exists($remoteKey, $remoteRow)) {
                continue;
            }

            $boolean = in_array($remoteKey, self::BOOLEAN_KEYS, true);
            $local = $this->normalise($localAttributes[$localColumn] ?? null, $boolean);
            $remote = $this->normalise($remoteRow[$remoteKey], $boolean);

            if ($local !== $remote) {
                $drift[] = ['entity' => $entity, 'id' => $id, 'field' => $localColumn, 'local' => $local, 'remote' => $remote];
            }
        }

        return $drift;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function keyRemote(array $rows, string $idKey): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! filled($row[$idKey] ?? null)) {
                continue;
            }

            $keyed[(string) $row[$idKey]] = $row;
        }

        return $keyed;
    }

    /**
     * Compare values, not representations.
     *
     * Booleans arrive as `1`/`true`/`"1"` depending on driver and JSON, and an
     * empty string upstream means the same as NULL locally. Without this the
     * report fills with drift nobody can act on — and a report full of noise is
     * a report that stops being read, which costs more than having none.
     */
    private function normalise(mixed $value, bool $boolean): mixed
    {
        if ($boolean) {
            // Only where the field is DECLARED boolean, so a string field whose
            // value happens to be "1" can never compare equal to a remote `true`.
            return $value === null ? null : (bool) $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? trim($value) : $value;
    }
}
