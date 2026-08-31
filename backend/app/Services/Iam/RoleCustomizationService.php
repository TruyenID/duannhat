<?php

declare(strict_types=1);

namespace App\Services\Iam;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Copy-on-write per-org role customization (plan-fix-issue-847 P1).
 *
 * A tenant editing a GLOBAL system role template must not rewrite the shared row
 * (that was the #844 cross-tenant vuln). Instead we fork the template into an
 * org-scoped copy the first time the org edits it, copy the template's permissions,
 * and repoint that org's role assignments (role_user_pivots) from the template to the
 * copy — so the org edits its own matrix in isolation and the template stays pristine.
 */
final class RoleCustomizationService
{
    /**
     * Apply an org's chosen permission set to a role, forking the template first when
     * the role is still global (#1666, moved out of `IamRoleController::update`).
     *
     * ## Why one transaction and not two
     *
     * The controller ran `cloneForOrg()` — itself atomic — and THEN opened a second
     * transaction for the sync. Between the two, a failure left the org in a state it
     * never asked for: forked off the shared template, every assignment repointed to
     * the fork, and the fork still carrying the TEMPLATE's permissions rather than the
     * edit. Permissions were unchanged, so nobody gained access — but the org was now
     * permanently detached from the template, and the admin saw an error and assumed
     * nothing had happened. Retrying heals it (`firstOrCreate` is idempotent), which is
     * exactly why it could sit there unnoticed.
     *
     * ## Why the lockout guard moved inside
     *
     * It was already correct to run it BEFORE the fork — a rejected edit must not leave
     * an orphaned fork behind. Running it inside the same transaction keeps that order
     * and additionally narrows the window where a concurrent edit strips the last
     * IAM-managing role between the check and the sync.
     *
     * It still `abort()`s rather than throwing a domain exception: the 422 body carries
     * `code: IAM_LAST_ADMIN_LOCKOUT`, which admin-web branches on, and inventing an
     * exception class for one call site would only add a place for that string to drift.
     *
     * @param  list<string>  $permissionSlugs  may be empty — clearing every permission is legal
     * @return Role the role the permissions landed on (the fork, when one was made)
     */
    public function applyPermissions(
        Role $role,
        string $consoleOrgId,
        string $localOrgId,
        array $permissionSlugs,
    ): Role {
        return DB::transaction(function () use ($role, $consoleOrgId, $localOrgId, $permissionSlugs): Role {
            $this->assertNoSelfLockout($role, $localOrgId, $permissionSlugs);

            if ($role->console_organization_id === null) {
                $role = $this->cloneForOrg($role, $consoleOrgId, $localOrgId);
            }

            $role->permissions()->sync(
                Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id')->all(),
            );

            return $role;
        });
    }

    /**
     * An org must not remove the last role that can manage IAM permissions.
     *
     * If the edit drops `iam.permissions`, at least one OTHER role actually ASSIGNED
     * within the org (via `role_user_pivots`) must still grant it. An unassigned global
     * template does NOT count — otherwise the guard would be a no-op everywhere
     * `IamSeeder` ran, since the global org-admin always grants `iam.permissions`.
     *
     * Excluding the role's own id is equivalent to excluding its fork: both are "the
     * role being edited", and the guard deliberately runs before the fork exists.
     *
     * @param  list<string>  $newSlugs
     */
    private function assertNoSelfLockout(Role $role, string $localOrgId, array $newSlugs): void
    {
        if (in_array('iam.permissions', $newSlugs, true)) {
            return; // this role keeps it → safe
        }

        $survivorExists = Role::query()
            ->where('roles.id', '!=', $role->id)
            ->whereIn('roles.id', function ($query) use ($localOrgId) {
                $query->select('role_id')
                    ->from('role_user_pivots')
                    ->where('organization_id', $localOrgId);
            })
            ->whereHas('permissions', fn ($query) => $query->where('slug', 'iam.permissions'))
            ->exists();

        if (! $survivorExists) {
            abort(response()->json([
                'message' => 'Cannot remove the last role that can manage IAM permissions — the organization would be locked out of permission management.',
                'code' => 'IAM_LAST_ADMIN_LOCKOUT',
            ], 422));
        }
    }

    /**
     * Clone a global template role into an org-scoped copy for $consoleOrgId, copy its
     * permissions, and repoint the org's pivots template → clone. Atomic, idempotent and
     * race-safe: returns the existing org-scoped copy if one already exists.
     *
     * @param  Role  $template  a GLOBAL role (console_organization_id IS NULL)
     * @param  string  $consoleOrgId  the caller's console organization id (roles scope)
     * @param  string  $localOrgId  the caller's local organizations.id (pivot scope)
     */
    public function cloneForOrg(Role $template, string $consoleOrgId, string $localOrgId): Role
    {
        return DB::transaction(function () use ($template, $consoleOrgId, $localOrgId) {
            $template->loadMissing('permissions');

            // Race-safe get-or-create on the unique (console_organization_id, slug). On a
            // concurrent edit, Laravel's createOrFirst catches the unique violation and
            // re-queries instead of throwing.
            //
            // NOTE: when reset-to-template ships (soft-deleting org copies), switch to
            // Role::withTrashed()->...->restore() — the unique index excludes deleted_at,
            // so a trashed copy would collide here. Moot until that flow exists.
            $clone = Role::firstOrCreate(
                ['console_organization_id' => $consoleOrgId, 'slug' => $template->slug],
                ['name' => $template->name, 'description' => $template->description, 'level' => $template->level],
            );

            if ($clone->wasRecentlyCreated) {
                $clone->permissions()->sync($template->permissions->pluck('id')->all());
            }

            // Repoint this org's assignments (all branches) from the template to the clone.
            // Legacy model: real rows fork. godx model: 0 rows — harmless no-op. Global
            // (organization_id IS NULL) assignments are intentionally left on the template.
            DB::table('role_user_pivots')
                ->where('role_id', $template->id)
                ->where('organization_id', $localOrgId)
                ->update(['role_id' => $clone->id, 'updated_at' => now()]);

            return $clone;
        });
    }
}
