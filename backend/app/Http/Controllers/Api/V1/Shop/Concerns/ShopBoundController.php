<?php

namespace App\Http\Controllers\Api\V1\Shop\Concerns;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use Illuminate\Http\Request;

/**
 * Plan-023 M6 — shared shop-context resolution + cross-tenant guard
 * for shop notification controllers.
 *
 * Every action method calls `$this->requireShop($request)` to pull
 * the `Branch` ResolveShopFromSlug middleware attached, then runs
 * `$this->authorize($ability, [Notification::class, $shop])` against
 * the ShopNotificationPolicy. Row-level access is enforced by
 * scoping queries to `where('branch_id', $shop->id)` AND
 * `findOrFail($id)` so cross-shop probes return 404.
 *
 * `requireBrandForShop($shop)` resolves the brand context the shop
 * belongs to — used by audience/template rows that carry brand_id
 * in addition to branch_id, and by the audience preview which is
 * always brand-anchored.
 */
trait ShopBoundController
{
    protected function requireShop(Request $request): Branch
    {
        $shop = $request->attributes->get('shop');
        if (! $shop instanceof Branch) {
            abort(500, 'ResolveShopFromSlug middleware did not bind the shop attribute.');
        }

        return $shop;
    }

    protected function requireBrandForShop(Branch $shop): ?Brand
    {
        return $shop->console_brand_id
            ? Brand::where('console_brand_id', $shop->console_brand_id)->first()
            : null;
    }

    /**
     * @return array<int, string>
     */
    protected function shopOrgIds(Branch $shop): array
    {
        return Organization::query()
            ->where('console_organization_id', $shop->console_organization_id)
            ->pluck('id')
            ->all();
    }
}
