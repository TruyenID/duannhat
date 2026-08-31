<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HQ\Iam;

use App\Http\Controllers\Controller;
use App\Http\Requests\HQ\Iam\AssignRoleRequest;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * IAM member management — list, inspect, assign, and revoke org-scoped roles.
 *
 * tempo-native: role assignments live entirely in the local `role_user_pivots`
 * table, while service access and effective permissions remain authoritative
 * on Platform.
 *
 * All routes are mounted under /api/v1/hq/{brandSlug}/iam/ and protected by
 * ResolveBrandFromSlug middleware (sets request.organization_id).
 */
class IamMemberController extends Controller
{
    /**
     * List all users with any role assignment in this organization.
     *
     * Requires: iam.member.view permission.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (! $user->hasPermission('iam.member.view', $orgId)) {
            abort(403, 'You do not have permission to view members.');
        }

        $members = User::whereHas('roles', function ($q) use ($orgId) {
            $q->where('role_user_pivots.organization_id', $orgId);
        })
            ->with(['roles' => function ($q) use ($orgId) {
                $q->where('role_user_pivots.organization_id', $orgId)
                    ->withPivot('organization_id', 'branch_id');
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $members->map(fn ($member) => $this->formatMember($member, $orgId)),
        ]);
    }

    /**
     * Show a single member's role assignments in this organization.
     *
     * Requires: iam.member.view permission.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $caller = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (! $caller->hasPermission('iam.member.view', $orgId)) {
            abort(403, 'You do not have permission to view members.');
        }

        $hasAnyRole = $user->roles()
            ->where('role_user_pivots.organization_id', $orgId)
            ->exists();

        if (! $hasAnyRole) {
            abort(404, 'User is not a member of this organization.');
        }

        $user->load(['roles' => function ($q) use ($orgId) {
            $q->where('role_user_pivots.organization_id', $orgId)
                ->withPivot('organization_id', 'branch_id');
        }]);

        return response()->json(['data' => $this->formatMember($user, $orgId)]);
    }

    /**
     * Assign a role to a user in this organization. Local-only.
     *
     * Requires: iam.assign permission.
     * Guard: caller's highest role level must exceed the assigned role's level.
     */
    public function assign(Request $request, AssignRoleRequest $assignRequest, User $user): JsonResponse
    {
        $caller = $request->user();
        $orgId = $request->attributes->get('organization_id');

        $role = $assignRequest->role();
        $branchId = $assignRequest->validated('branch_id');

        // Check iam.assign in the target branch context so that branch-scoped
        // shop-managers (pivot.branch_id = X) pass the check when assigning to their branch.
        if (! $caller->hasPermission('iam.assign', $orgId, $branchId)) {
            abort(403, 'You do not have permission to assign roles.');
        }

        // Level guard: caller must have a higher level than the role being assigned.
        // Use branch context so branch-scoped shop-managers are evaluated at their
        // branch level (60) rather than their org level (0 — no org-wide role).
        $callerLevel = $caller->getHighestRoleLevelInContext($orgId, $branchId);
        if ($callerLevel <= $role->level) {
            abort(403, 'You cannot assign a role at or above your own level.');
        }

        // Branch scope guard: if assigning to a specific branch, callers without an
        // org-wide role (org-admin/org-manager) can only target branches they already manage.
        if ($branchId) {
            $hasOrgWideRole = $caller->roles()
                ->wherePivot('organization_id', $orgId)
                ->wherePivotNull('branch_id')
                ->exists();

            if (! $hasOrgWideRole) {
                $managesBranch = $caller->roles()
                    ->wherePivot('organization_id', $orgId)
                    ->wherePivot('branch_id', $branchId)
                    ->exists();

                if (! $managesBranch) {
                    abort(403, 'You can only assign roles to branches you manage.');
                }
            }
        }

        $user->assignRole($role, $orgId, $branchId);

        $this->logIamAudit($request, $user, 'iam.role_assigned', [
            'organization_id' => $orgId,
            'role_slug' => $role->slug,
            'role_name' => $role->name,
            'branch_id' => $branchId,
        ]);

        $user->load(['roles' => function ($q) use ($orgId) {
            $q->where('role_user_pivots.organization_id', $orgId)
                ->withPivot('organization_id', 'branch_id');
        }]);

        return response()->json(['data' => $this->formatMember($user, $orgId)], 201);
    }

    /**
     * Revoke a specific scoped role from a user. Local-only.
     *
     * Requires: iam.assign permission.
     * Guard: caller's highest role level must exceed the revoked role's level.
     */
    public function revoke(Request $request, User $user, string $roleSlug): JsonResponse
    {
        $caller = $request->user();
        $orgId = $request->attributes->get('organization_id');
        $branchId = $request->query('branch_id');

        // Check with branch context so branch-scoped shop-managers can revoke from their branch.
        if (! $caller->hasPermission('iam.assign', $orgId, $branchId ?: null)) {
            abort(403, 'You do not have permission to revoke roles.');
        }

        $role = Role::where('slug', $roleSlug)->first();
        if (! $role) {
            abort(404, 'Role not found.');
        }

        // Verify the assignment exists.
        $query = $user->roles()->where('roles.id', $role->id);
        $query = $branchId
            ? $query->wherePivot('branch_id', $branchId)
            : $query->wherePivotNull('branch_id');
        $query = $query->wherePivot('organization_id', $orgId);

        if (! $query->exists()) {
            abort(404, 'Role assignment not found for this user in this context.');
        }

        // Level guard: use branch context so branch-scoped callers are evaluated at
        // their branch level (e.g., shop-manager=60) rather than their org level (0).
        $callerLevel = $caller->getHighestRoleLevelInContext($orgId, $branchId ?: null);
        if ($callerLevel <= $role->level) {
            abort(403, 'You cannot revoke a role at or above your own level.');
        }

        $user->removeRole($role, $orgId, $branchId ?: null);

        $this->logIamAudit($request, $user, 'iam.role_revoked', [
            'organization_id' => $orgId,
            'role_slug' => $role->slug,
            'role_name' => $role->name,
            'branch_id' => $branchId ?: null,
        ]);

        return response()->json(null, 204);
    }

    /**
     * Deactivate a member — they can no longer sign in (TC-MEM-DET5).
     *
     * Requires: iam.assign permission.
     * Guard: caller must outrank the target and cannot deactivate themselves.
     */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        return $this->setActiveState($request, $user, false);
    }

    /**
     * Reactivate a previously deactivated member (TC-MEM-DET5).
     *
     * Requires: iam.assign permission.
     */
    public function activate(Request $request, User $user): JsonResponse
    {
        return $this->setActiveState($request, $user, true);
    }

    /**
     * Toggle a member's local `is_active` flag. Local-only.
     */
    private function setActiveState(Request $request, User $user, bool $active): JsonResponse
    {
        $caller = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (! $caller->hasPermission('iam.assign', $orgId)) {
            abort(403, 'You do not have permission to manage members.');
        }

        $hasAnyRole = $user->roles()
            ->where('role_user_pivots.organization_id', $orgId)
            ->exists();

        if (! $hasAnyRole) {
            abort(404, 'User is not a member of this organization.');
        }

        // A member cannot lock themselves out.
        if ($caller->id === $user->id) {
            abort(403, 'You cannot change your own active status.');
        }

        // Level guard: caller must outrank the target's highest org role so a
        // lower-privileged member can't deactivate an admin.
        $callerLevel = $caller->getHighestRoleLevelInContext($orgId, null);
        $targetLevel = $user->getHighestRoleLevelInContext($orgId, null);
        if ($callerLevel <= $targetLevel) {
            abort(403, 'You cannot change the status of a member at or above your own level.');
        }

        $user->is_active = $active;
        $user->save();

        $this->logIamAudit($request, $user, $active ? 'iam.member_activated' : 'iam.member_deactivated', [
            'organization_id' => $orgId,
        ]);

        $user->load(['roles' => function ($q) use ($orgId) {
            $q->where('role_user_pivots.organization_id', $orgId)
                ->withPivot('organization_id', 'branch_id');
        }]);

        return response()->json(['data' => $this->formatMember($user, $orgId)]);
    }

    /**
     * List a member's effective permissions, grouped by role assignment.
     *
     * Each entry shows one role+scope pair and all permission slugs that role grants.
     * Requires: iam.member.view permission.
     */
    public function permissions(Request $request, User $user): JsonResponse
    {
        $caller = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (! $caller->hasPermission('iam.member.view', $orgId)) {
            abort(403, 'You do not have permission to view members.');
        }

        $hasAnyRole = $user->roles()
            ->where('role_user_pivots.organization_id', $orgId)
            ->exists();

        if (! $hasAnyRole) {
            abort(404, 'User is not a member of this organization.');
        }

        $user->load(['roles' => function ($q) use ($orgId) {
            $q->where('role_user_pivots.organization_id', $orgId)
                ->withPivot('organization_id', 'branch_id')
                ->with('permissions');
        }]);

        $data = $user->roles
            ->filter(fn ($role) => $role->pivot->organization_id === $orgId)
            ->map(fn ($role) => [
                'role_slug' => $role->slug,
                'role_name' => $role->name,
                'role_level' => $role->level,
                'branch_id' => $role->pivot->branch_id,
                'permissions' => $role->permissions->pluck('slug')->sort()->values(),
            ])
            ->values();

        return response()->json(['data' => $data]);
    }

    /**
     * Send a password-reset email to the member (TC-MEM-DET4).
     *
     * Uses Laravel's password broker — the member receives a reset link and
     * sets a new password themselves; admins never see or set the password.
     * Requires: iam.assign permission.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $caller = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (! $caller->hasPermission('iam.assign', $orgId)) {
            abort(403, 'You do not have permission to manage members.');
        }

        $hasAnyRole = $user->roles()
            ->where('role_user_pivots.organization_id', $orgId)
            ->exists();

        if (! $hasAnyRole) {
            abort(404, 'User is not a member of this organization.');
        }

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'Could not send the password-reset email. Try again in a moment.',
            ], 422);
        }

        $this->logIamAudit($request, $user, 'iam.password_reset_sent', [
            'organization_id' => $orgId,
        ]);

        return response()->json(['message' => 'Password-reset email sent.']);
    }

    /**
     * List the IAM audit trail for a member in this organization (TC-MEM-DET7).
     *
     * Returns role assign/revoke, activation, and password-reset events,
     * newest first, with the acting admin's name resolved.
     * Requires: iam.member.view permission.
     */
    public function audit(Request $request, User $user): JsonResponse
    {
        $caller = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (! $caller->hasPermission('iam.member.view', $orgId)) {
            abort(403, 'You do not have permission to view members.');
        }

        $hasAnyRole = $user->roles()
            ->where('role_user_pivots.organization_id', $orgId)
            ->exists();

        if (! $hasAnyRole) {
            abort(404, 'User is not a member of this organization.');
        }

        $logs = AuditLog::query()
            ->where('auditable_type', $user->getMorphClass())
            ->where('auditable_id', $user->getKey())
            ->where('action', 'like', 'iam.%')
            ->where('metadata->organization_id', $orgId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $actors = User::query()
            ->whereIn('id', $logs->pluck('user_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $logs->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_name' => $log->user_id ? ($actors[$log->user_id]->name ?? null) : null,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Write one IAM audit row against the target member. Never throws — an
     * audit failure must not roll back the business action.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function logIamAudit(Request $request, User $user, string $action, array $metadata): void
    {
        // Fail-open, và đó là hợp đồng: một lần vô hiệu hoá tài khoản ĐÃ thành
        // công phải trả về thành công kể cả khi dòng vết không ghi được. Việc
        // nuốt-lỗi-nhưng-vẫn-log giờ nằm ở AuditLogWriter (#1666).
        app(AuditLogWriter::class)->record(
            $user->getMorphClass(),
            (string) $user->getKey(),
            $action,
            $request->user()?->id === null ? null : (string) $request->user()->id,
            $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMember(User $member, string $orgId): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'avatar_url' => $member->avatar_url ?? null,
            'is_active' => (bool) $member->is_active,
            'assignments' => $member->roles
                ->filter(fn ($role) => $role->pivot->organization_id === $orgId)
                ->map(fn ($role) => [
                    'role_slug' => $role->slug,
                    'role_name' => $role->name,
                    'role_level' => $role->level,
                    'branch_id' => $role->pivot->branch_id,
                ])
                ->values(),
        ];
    }
}
