<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\Organization;

/**
 * Resolve a public branch slug to the three scope ids a record needs to be
 * attributable: branch, brand, organization.
 *
 * Why this is a contract instead of an inline lookup: the walk is
 * `slug → Branch → brand (console_brand_id) → Organization
 * (console_organization_id)`, and every caller outside the Organization module
 * that does it by hand reaches into three of this module's models. #1505 did
 * exactly that from `CustomerAuthService` and pushed the
 * `CustomerEngagement → Organization` edge count 52 → 54, turning the
 * module-boundary ratchet red on `dev` (#1526 — same shape as #1446).
 *
 * The rule the ratchet encodes: cross-module needs are legitimate, reaching
 * across to satisfy them is not. One owner, one walk, one place to fix when the
 * shadow-id join changes.
 */
final class PublicBranchScopeResolver
{
    /**
     * @return array{branch_id: string, brand_id: ?string, organization_id: ?string}|null
     *                                                                                    `null` when the slug names no ACTIVE branch — an inactive slug can
     *                                                                                    only be a stale URL or a hand-typed one, because
     *                                                                                    `GET /customer/branches` lists active branches only.
     */
    public function forPublicSlug(?string $slug): ?array
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        $branch = Branch::with('brand')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $branch) {
            return null;
        }

        // Organization is derived from the branch, never taken from the client.
        // A branch missing `console_organization_id` is half-configured: still
        // resolvable (the customer is not responsible for that), but the
        // organization stays null rather than being guessed.
        $organization = $branch->console_organization_id
            ? Organization::where('console_organization_id', $branch->console_organization_id)->first()
            : null;

        return [
            'branch_id' => (string) $branch->id,
            'brand_id' => $branch->brand?->id,
            'organization_id' => $organization?->id,
        ];
    }
}
