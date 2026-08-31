<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Organization;

/**
 * Resolves the compliance profile for an organization from its mirrored
 * operating country (organizations.operating_country, adopted from the
 * Platform by UserProvisioner — see #1153).
 *
 * Fail-safe to JP: a missing organization row, a legacy row predating the
 * column, or a country without a profile all resolve to the JP profile —
 * exactly the behavior every tenant had before #1153. No warning is raised
 * for the default: compliance posture is a tenant fact, not a nag.
 *
 * Deliberately NOT a container singleton: the memo below is per-instance so
 * a long-running worker never pins a stale profile across the lazy country
 * adoption UserProvisioner performs at login. The lookup it saves is a
 * single indexed query.
 */
final class ComplianceProfileResolver
{
    /** @var array<string, ComplianceProfile> per-instance memo, keyed by console org id */
    private array $memo = [];

    public function forOrganization(?string $consoleOrganizationId): ComplianceProfile
    {
        $key = (string) $consoleOrganizationId;

        return $this->memo[$key] ??= $this->forCountry(
            $key === '' ? null : Organization::query()
                ->where('console_organization_id', $key)
                ->value('operating_country'),
        );
    }

    public function forCountry(?string $country): ComplianceProfile
    {
        return match (strtoupper((string) $country)) {
            'VN' => new VnComplianceProfile,
            default => new JpComplianceProfile,
        };
    }
}
