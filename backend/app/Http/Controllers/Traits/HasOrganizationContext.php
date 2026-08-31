<?php

namespace App\Http\Controllers\Traits;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;

trait HasOrganizationContext
{
    /**
     * Resolve the **local** Organization primary key for the current user.
     *
     * Notes on the two ID concepts:
     *
     * - `users.console_organization_id` (and the matching column on every
     *   tenant-scoped table) holds the cross-system stable identifier that
     *   SSO sync writes — fixed across `migrate:fresh` on either side.
     * - `organizations.id` is the **local** Eloquent primary key, generated
     *   on insert. Foreign keys from tenant tables (Zone, Table, Brand,
     *   Branch, ...) point at this local `id`.
     *
     * Earlier this method returned `console_organization_id` directly, which
     * caused FK violations on inserts (e.g. POST /zones blew up with
     * `Cannot add or update a child row`) because the FK target was the
     * local PK, not the shadow column. We now look up the local Organization
     * by the user's shadow id and return that — cached on the request so
     * one HTTP cycle never hits the DB twice.
     */
    protected function getOrganizationId(): string
    {
        // Device-authenticated POS requests (AuthenticateSsoOrDevice): the
        // Device model has no `console_organization_id` accessor mapped to
        // an SSO org, but `ResolvesShopContext` already resolved the local
        // Organization from the shop's `console_organization_id` and stamped
        // it onto the request. Prefer that when present — it's the same
        // local PK we'd compute below for an SSO user.
        $orgId = request()->attributes->get('organization_id');
        if ($orgId) {
            return $orgId;
        }

        $user = request()->user();

        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $consoleOrgId = $user->console_organization_id;

        if (! $consoleOrgId) {
            abort(400, 'No organization assigned');
        }

        $cacheKey = 'organization_id:'.$consoleOrgId;
        $cached = request()->attributes->get($cacheKey);

        if ($cached) {
            return $cached;
        }

        $localId = Organization::where('console_organization_id', $consoleOrgId)
            ->value('id');

        if (! $localId) {
            abort(403, 'Organization not found in this service.');
        }

        request()->attributes->set($cacheKey, $localId);

        return $localId;
    }

    protected function authorizeOrganization(Model $model): void
    {
        if ($model->organization_id !== $this->getOrganizationId()) {
            abort(403, 'Resource does not belong to your organization');
        }
    }

    /**
     * Enforce that a brand-owned model belongs to the brand on the current
     * route (`/hq/{brandSlug}/...`).
     *
     * `ResolveBrandFromSlug` stashes the resolved `brand_id` on the request
     * attributes. List endpoints already filter by it, but single-resource
     * endpoints (`show`/`update`/`destroy`) bind the model by its global id,
     * so without this guard a member of org X could read or mutate a sibling
     * brand's resource simply by swapping the slug in the URL — the brand
     * boundary was never checked (only the org). We abort **404** rather than
     * 403 so the response does not leak the existence of another brand's row.
     *
     * No-op when the request carries no brand context (non-`{brandSlug}` routes).
     */
    protected function authorizeBrand(Model $model): void
    {
        $routeBrandId = request()->attributes->get('brand_id');

        if ($routeBrandId !== null && $model->getAttribute('brand_id') !== $routeBrandId) {
            abort(404, 'Resource not found in this brand.');
        }
    }
}
