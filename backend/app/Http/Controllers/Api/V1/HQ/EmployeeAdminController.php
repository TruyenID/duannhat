<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only Tempo mirror of the Platform-owned employee roster.
 *
 * Before Platform SSO this was a legacy identity proxy
 * (/api/sso/users + /api/sso/invite). Console is dead, so:
 *
 * Platform owns invite, role changes, and member removal. Tempo exposes only
 * the local role-assignment mirror; registering mutation routes here would
 * falsely promise a second identity authority (#2367).
 */
class EmployeeAdminController extends Controller
{
    /**
     * GET /hq/{brand}/employees
     *
     * Local roster: every user with any role assignment in the brand's
     * organization. Requires iam.member.view.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (! $user->hasPermission('iam.member.view', $orgId)) {
            abort(403, 'You do not have permission to view employees.');
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
            'data' => $members->map(fn (User $member) => [
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
            ])->values(),
            'all_users_access' => true,
        ]);
    }
}
