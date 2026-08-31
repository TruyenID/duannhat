<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HQ\Iam;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * IAM permission catalogue — returns all permissions grouped by domain.
 *
 * Requires: iam.member.view permission (visible to managers+).
 */
class IamPermissionController extends Controller
{
    /**
     * List all permissions grouped by domain (catalog, material, menu, inventory, shop, iam).
     *
     * Requires: iam.member.view permission.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id');

        if (! $user->hasPermission('iam.member.view', $orgId)) {
            abort(403, 'You do not have permission to view permissions.');
        }

        $permissions = Permission::orderBy('group')->orderBy('slug')->get();

        $grouped = $permissions->groupBy('group')->map(fn ($perms, $group) => [
            'group' => $group,
            'permissions' => $perms->map(fn ($p) => [
                'slug' => $p->slug,
                'name' => $p->name,
            ])->values(),
        ])->values();

        return response()->json(['data' => $grouped]);
    }
}
