<?php

namespace App\Policies\Traits;

use App\Models\Organization;
use App\Models\User;
use App\Policies\MenuPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared authorization helpers for shop-scoped resources (Zone, Table, …).
 *
 * Resolution order for "which shop are we operating against":
 *   1. ResolveShopFromSlug middleware sets request()->attributes->shop_id
 *   2. If absent (e.g. unit test), fall back to the model's branch_id
 *
 * The user's organization is always taken from console_organization_id.
 */
trait ChecksShopContext
{
    /**
     * Verify a shop-scoped model belongs to the user's organization.
     *
     * ⚠️ DO NOT "simplify" this method to a direct
     *     `$user->console_organization_id === $model->organization_id`
     * comparison even though it looks tempting and reads cleaner.
     *
     * The two columns live in DIFFERENT id spaces:
     *
     *   - `{model}.organization_id` — LOCAL Organization PK (auto-generated UUID
     *     by HasUuids when the row is inserted into the `organizations` table).
     *   - `users.console_organization_id` — SHADOW id mirrored from the upstream
     *     IdP / console (set by the SSO layer; the IdP owns this value and we
     *     cannot influence it).
     *
     * In **production**, these two values are NEVER equal because the SSO IdP
     * issues `console_organization_id` independently from our local DB. The only
     * place they accidentally coincide is in `tests/Pest.php`, where the test
     * harness force-aligns both columns to make factories simpler. That makes
     * a broken direct-comparison version "pass" in unit tests but immediately
     * 403 against any real seeded data (MockDataSeeder + production both leave
     * the local PK as a fresh UUID).
     *
     * The correct comparison goes via the model's `organization` relation so
     * we line `organization.console_organization_id` (shadow id, joined from
     * the parent table) up against `$user->console_organization_id`. This is
     * the same pattern used by the HQ-side {@see MenuPolicy::belongsToUserOrganization()}.
     *
     * Requirement: every model that uses `ChecksShopContext` MUST expose a
     * `organization()` BelongsTo relation pointing to {@see Organization}.
     * Currently satisfied by Menu, Zone, and Table.
     *
     * History: 2026-04-14 — fixed silent 403 on every shop endpoint.
     */
    protected function belongsToOrganization(User $user, Model $model): bool
    {
        if (! $user->console_organization_id) {
            return false;
        }

        $modelConsoleOrgId = $model->organization?->console_organization_id;

        return $modelConsoleOrgId !== null
            && $modelConsoleOrgId === $user->console_organization_id;
    }

    protected function belongsToResolvedShop(Model $model): bool
    {
        $shopId = request()?->attributes->get('shop_id');

        if ($shopId === null) {
            return true; // no shop context resolved, fall through to org check
        }

        return $model->branch_id === $shopId;
    }

    protected function isOrgAdmin(User $user): bool
    {
        $organizationId = request()?->attributes->get('organization_id');

        // Platform provisions service roles as organization-scoped slugs such
        // as `tempo-admin`, not as the local template slug `org-admin`.
        // Authorize the admin capability by its canonical permission so both
        // role families (and customized full-access roles) behave identically.
        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasPermission('iam.permissions', $organizationId);
    }

    protected function isShopManager(User $user, ?string $branchId = null): bool
    {
        if ($this->isOrgAdmin($user)) {
            return true;
        }

        $organizationId = request()?->attributes->get('organization_id');

        return $user->hasRoleInContext('org-manager', $organizationId, $branchId)
            || $user->hasRoleInContext('shop-manager', $organizationId, $branchId);
    }

    protected function isShopUser(User $user, ?string $branchId = null): bool
    {
        if ($this->isShopManager($user, $branchId)) {
            return true;
        }

        $organizationId = request()?->attributes->get('organization_id');

        return $user->hasRoleInContext('staff', $organizationId, $branchId)
            || $user->hasRoleInContext('shop-staff', $organizationId, $branchId);
    }
}
