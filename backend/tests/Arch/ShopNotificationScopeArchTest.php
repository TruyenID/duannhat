<?php

/**
 * Plan-023 M6 T6.12 — guard that shop notification controllers never
 * authorize against a Brand directly.
 *
 * The whole point of M6 is that `shop_admin` of shop A cannot use
 * shop-route surfaces to read/write shop B's data, even if they hold
 * brand-level access. The controllers achieve this by:
 *   1. Pulling Branch from the ResolveShopFromSlug-bound attribute.
 *   2. Authorizing via ShopNotificationPolicy (which gates on Branch).
 *   3. Filtering DB queries with `where('branch_id', $shop->id)`.
 *
 * If a future reviewer slips a `$this->authorize(..., $brand)` call
 * into a shop controller — or pulls `$request->attributes->get(
 * 'brand')` directly to gate access — this arch test fails the build.
 */

use Illuminate\Support\Facades\File;

it('shop notification controllers never authorize against a Brand', function () {
    $controllers = File::glob(base_path('app/Http/Controllers/Api/V1/Shop/ShopNotification*.php'));
    expect($controllers)->not->toBeEmpty();

    foreach ($controllers as $path) {
        $source = file_get_contents($path);

        // Authorize calls MUST take the shop policy ability (manageX, viewAudit)
        // and a Branch instance — never a Brand. Easiest guard: no naked
        // `$brand` variable in authorize() calls.
        expect($source)
            ->not->toMatch('/authorize\([^)]*\$brand\b/')
            ->not->toContain("attributes->get('brand'");

        // And every controller must include the shop-bound trait so the
        // Branch resolution stays uniform.
        expect($source)->toContain('ShopBoundController');
    }
});

it('shop notification controllers scope queries by branch_id', function () {
    $controllers = File::glob(base_path('app/Http/Controllers/Api/V1/Shop/ShopNotification*.php'));

    foreach ($controllers as $path) {
        $source = file_get_contents($path);
        // The audit list controller uses aggregation_key filtering instead
        // of a direct branch_id column on Notification, so we accept either
        // pattern. New shop controllers MUST adopt one.
        expect($source)->toMatch('/branch_id|aggregation_key/');
    }
});
