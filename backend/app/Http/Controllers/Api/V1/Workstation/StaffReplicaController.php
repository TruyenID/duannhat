<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/workstation/staff
 *
 * Replica feed for the workstation's PullStaff. Returns the same active
 * staff list that /api/v1/pos/staff produces, but resolves the
 * organization scope from the authenticated workstation device instead
 * of the X-Shop-Slug header — these endpoints are device-owned, not
 * user-session-owned.
 *
 * Shape kept compatible with pos-web's /pos/staff client (id, name,
 * email, avatar_url) so the LAN-served handler can reuse the same
 * envelope verbatim.
 */
class StaffReplicaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $orgId = $device?->organization_id;
        if (! $orgId) {
            return response()->json(['data' => []]);
        }

        $userIds = DB::table('role_user_pivots')
            ->where('organization_id', $orgId)
            ->distinct()
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'avatar_url']);

        return response()->json([
            'data' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar_url' => $u->avatar_url,
            ])->all(),
        ]);
    }
}
