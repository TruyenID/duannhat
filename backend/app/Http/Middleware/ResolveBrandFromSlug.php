<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveBrandFromSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        $brandSlug = $request->route('brandSlug');

        if (! $brandSlug) {
            abort(400, 'Brand slug is required.');
        }

        $brandQuery = Brand::query()
            ->where('slug', $brandSlug)
            ->where('is_active', true);

        $user = $request->user();

        if ($user) {
            $organizationIds = DB::table('role_user_pivots')
                ->where('user_id', $user->id)
                ->pluck('organization_id');
            $consoleOrganizationIds = Organization::query()
                ->whereIn('id', $organizationIds)
                ->pluck('console_organization_id');

            $brand = (clone $brandQuery)
                ->whereIn('console_organization_id', $consoleOrganizationIds)
                ->first();
        } else {
            $brand = $brandQuery->first();
        }

        if (! $brand) {
            if ($user && $brandQuery->exists()) {
                abort(403, 'You do not have access to this brand.');
            }

            abort(404, 'Brand not found.');
        }

        // Resolve the local Organization record for this brand.
        // Brand has no direct organization_id FK — join via console_organization_id.
        $organization = Organization::where('console_organization_id', $brand->console_organization_id)
            ->first();

        if ($user) {
            // IAM check: user must have at least one role assignment for this org.
            // Replaces the old single-org `console_organization_id` comparison which
            // broke for users managing multiple brands.
            // If no Organization record is found, the brand has no IAM mapping → deny.
            if (! $organization) {
                abort(403, 'You do not have access to this brand.');
            }

            $hasAccess = DB::table('role_user_pivots')
                ->where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->exists();

            if (! $hasAccess) {
                abort(403, 'You do not have access to this brand.');
            }
        }

        $request->attributes->set('brand', $brand);
        $request->attributes->set('brand_id', $brand->id);
        $request->attributes->set('organization', $organization);
        $request->attributes->set('organization_id', $organization?->id);

        // Drop the brandSlug route parameter so controller methods don't have
        // to declare `string $brandSlug` as the first argument — model binding
        // by parameter name still works for the remaining `{productType}` etc.
        $request->route()->forgetParameter('brandSlug');

        return $next($request);
    }
}
