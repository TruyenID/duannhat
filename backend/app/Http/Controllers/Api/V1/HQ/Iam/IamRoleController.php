<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HQ\Iam;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\Iam\RoleCustomizationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * IAM role management — list roles with their permissions, update permission assignments.
 *
 * Updating permissions requires org-admin level (iam.permissions permission).
 */
class IamRoleController extends Controller
{
    public function __construct(private readonly RoleCustomizationService $customization) {}

    /**
     * List roles with their assigned permissions.
     *
     * Requires: iam.member.view permission (visible to managers+).
     *
     * Scoping (#844): a tenant sees the shared system/global role templates
     * (console_organization_id IS NULL) plus role definitions belonging to its own
     * console organization — never another tenant's org-scoped rows.
     *
     * De-dup (plan-fix-issue-847): once an org forks a template, both the global row
     * and the org-scoped copy share a slug. Surface exactly one row per slug, preferring
     * the org-scoped copy, so the FE permission matrix never renders duplicate columns.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (! $user->hasPermission('iam.member.view', $orgId)) {
            abort(403, 'You do not have permission to view roles.');
        }

        $callerConsoleOrgId = $request->attributes->get('organization')?->console_organization_id;

        /** @var Collection<int, Role> $roles */
        $roles = Role::with('permissions')
            ->where(function ($query) use ($callerConsoleOrgId) {
                $query->whereNull('console_organization_id')
                    ->orWhere('console_organization_id', $callerConsoleOrgId);
            })
            ->orderByDesc('level')
            ->get();

        $bySlug = $roles->groupBy('slug');

        $deduped = $bySlug
            ->map(fn ($group) => $group->firstWhere('console_organization_id', '!=', null) ?? $group->first())
            ->values()
            ->sortByDesc('level')
            ->values();

        return response()->json([
            'data' => $deduped->map(function (Role $role) use ($bySlug) {
                $group = $bySlug->get($role->slug);
                $hasGlobal = $group->contains(fn (Role $r) => $r->console_organization_id === null);
                $hasOrg = $group->contains(fn (Role $r) => $r->console_organization_id !== null);

                return [
                    'id' => $role->id,
                    'slug' => $role->slug,
                    'name' => $role->name,
                    'level' => $role->level,
                    'description' => $role->description,
                    'permissions' => $role->permissions->pluck('slug')->sort()->values(),
                    // A pristine global template (not yet forked by this org).
                    'is_system' => $role->console_organization_id === null,
                    // An org-scoped fork shadowing a global template.
                    'is_customized' => $hasOrg && $hasGlobal,
                    // Editing a system template forks it server-side (copy-on-write).
                    'is_editable' => true,
                ];
            }),
        ]);
    }

    /**
     * Sync a role's permission set.
     *
     * Requires: iam.permissions permission (org-admin only).
     *
     * Copy-on-write (plan-fix-issue-847): editing a GLOBAL system template forks it into
     * an org-scoped copy for the caller's console org (keeping the shared template
     * pristine — #844), repoints the org's role assignments, and applies the edit to the
     * copy. Roles already scoped to the caller's org are edited in place; a role owned by
     * another tenant is a 404.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (! $user->hasPermission('iam.permissions', $orgId)) {
            abort(403, 'Only org-admin can modify role permissions.');
        }

        $callerConsoleOrgId = $request->attributes->get('organization')?->console_organization_id;

        // Cross-tenant role → do not even acknowledge its existence.
        if ($role->console_organization_id !== null
            && (string) $role->console_organization_id !== (string) $callerConsoleOrgId) {
            abort(404, 'Role not found.');
        }

        $validated = $request->validate([
            'permission_slugs' => ['present', 'array'],   // 'present' allows [] (clear all)
            'permission_slugs.*' => ['string', 'exists:permissions,slug'],
        ]);

        // Lockout guard, copy-on-write fork (#844) and the sync are ONE transaction in
        // the service (#1666) — running the fork and the sync as two left the org
        // detached from the shared template carrying the template's old permissions.
        $this->customization->applyPermissions(
            $role,
            (string) $callerConsoleOrgId,
            (string) $orgId,
            $validated['permission_slugs'],
        );

        return response()->json(null, 204);
    }
}
