<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Plan 030 — GET /api/v1/pos/staff
 *
 * Returns the active staff list for the shop resolved via ResolvePosShop
 * (X-Shop-Slug header). pos-web uses this to populate the "Người mở ca"
 * dropdown on the Open Shift form.
 *
 * Scope = the shop's organization. Any user that has a role on that
 * organization (via role_user_pivots) and is_active=true is considered
 * eligible to open a shift. We deliberately do NOT filter by branch
 * because users don't have a hard branch FK today — role grants are at
 * the org level. Filtering further (cashier-only, branch-only) is a
 * future refinement.
 *
 * The endpoint is workstation-mirrored: pos-web hits the workstation LAN
 * first; the workstation's PosStaffHandler proxies to this endpoint with
 * the original Authorization + X-Shop-Slug headers.
 */
class PosStaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        $org = Organization::where('console_organization_id', $shop->console_organization_id)->first();
        if (! $org) {
            return response()->json(['data' => []]);
        }

        // Distinct user ids with a role on this organization.
        $userIds = DB::table('role_user_pivots')
            ->where('organization_id', $org->id)
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
