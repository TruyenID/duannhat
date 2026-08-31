<?php

namespace App\Http\Middleware\Concerns;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shared shop-context resolution used by both ResolveShopFromSlug
 * (slug in URL — admin-web / HQ) and ResolvePosShop (slug in
 * X-Shop-Slug header — pos-web / POS terminals).
 *
 * Both middlewares set the same request attributes so downstream
 * controllers don't care how the slug arrived.
 */
trait ResolvesShopContext
{
    /**
     * Look up the shop by slug, run the IAM check against the authenticated
     * user, and populate request attributes. Aborts with 404 / 403 / 400
     * exactly as ResolveShopFromSlug did before extraction.
     */
    protected function bindShopContext(Request $request, string $shopSlug): void
    {
        $shop = Branch::where('slug', $shopSlug)
            ->where('is_active', true)
            ->first();

        if (! $shop) {
            abort(404, 'Shop not found.');
        }

        $organization = Organization::where('console_organization_id', $shop->console_organization_id)->first();

        // Device auth (POS via AuthenticateSsoOrDevice): the device is
        // pinned to a single branch at pairing time, so the only
        // authorization check that makes sense is "does this device
        // belong to the branch the client just addressed?". `(string)`
        // cast is critical — both columns are UUIDs; `(int)` on a UUID
        // yields 0 and would compare 0 === 0, bypassing security.
        $device = $request->attributes->get('device');
        if ($device) {
            if ((string) $device->branch_id !== (string) $shop->id) {
                abort(response()->json([
                    'message' => 'Device not authorized for this shop.',
                    'code' => 'BRANCH_MISMATCH',
                ], 403));
            }
        }

        $user = $request->user();

        // SSO users only — device requests already validated above.
        // (When device auth is active, `$request->user()` resolves to
        // the Device model, whose id ≠ any users.id, so running the IAM
        // check would 403 every legitimate device call.)
        if ($user && ! $device) {
            if (! $organization) {
                abort(403, 'Shop does not belong to a known organization.');
            }

            $hasAccess = DB::table('role_user_pivots')
                ->where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->where(fn ($scope) => $scope
                    ->whereNull('branch_id')
                    ->orWhere('branch_id', $shop->id))
                ->exists();

            if (! $hasAccess) {
                abort(403, 'You do not have access to this shop.');
            }
        }

        // Resolve the LOCAL brand PK from the shop's console brand id.
        $localBrandId = $shop->console_brand_id
            ? Brand::where('console_brand_id', $shop->console_brand_id)->value('id')
            : null;

        $request->attributes->set('shop', $shop);
        $request->attributes->set('shop_id', $shop->id);
        $request->attributes->set('brand_id', $localBrandId);
        $request->attributes->set('console_brand_id', $shop->console_brand_id);
        $request->attributes->set('organization', $organization);
        $request->attributes->set('organization_id', $organization?->id);
    }
}
