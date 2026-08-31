<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveBranchFromSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        $branchSlug = $request->route('branchSlug');

        if (! $branchSlug) {
            abort(400, 'Branch slug is required.');
        }

        $branch = Branch::where('slug', $branchSlug)
            ->where('is_active', true)
            ->first();

        if (! $branch) {
            abort(404, 'Branch not found.');
        }

        $organization = Organization::where('console_organization_id', $branch->console_organization_id)->first();

        $user = $request->user();

        if ($user) {
            if (! $organization) {
                abort(403, 'Branch does not belong to a known organization.');
            }

            $hasAccess = DB::table('role_user_pivots')
                ->where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->exists();

            if (! $hasAccess) {
                abort(403, 'You do not have access to this branch.');
            }
        }

        $localBrandId = $branch->console_brand_id
            ? Brand::where('console_brand_id', $branch->console_brand_id)->value('id')
            : null;

        $request->attributes->set('branch', $branch);
        $request->attributes->set('branch_id', $branch->id);
        $request->attributes->set('brand_id', $localBrandId);
        $request->attributes->set('organization', $organization);
        $request->attributes->set('organization_id', $organization?->id);

        $request->route()->forgetParameter('branchSlug');

        return $next($request);
    }
}
