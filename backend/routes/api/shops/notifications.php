<?php

use App\Http\Controllers\Api\V1\Shop\ShopNotificationAdminController;
use App\Http\Controllers\Api\V1\Shop\ShopNotificationAudienceController;
use App\Http\Controllers\Api\V1\Shop\ShopNotificationBroadcastController;
use App\Http\Controllers\Api\V1\Shop\ShopNotificationChannelRouteController;
use App\Http\Controllers\Api\V1\Shop\ShopNotificationTemplateController;
use Illuminate\Support\Facades\Route;

/*
 * Plan-023 M6 T6.5 — shop-level notification admin routes.
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix and
 * the ResolveShopFromSlug middleware (which binds the Branch row to
 * $request->attributes->get('shop')).
 *
 * Per-route throttle reuses the org-scoped names defined in
 * AppServiceProvider::configureRateLimiters (plan-012 issue #171):
 *   - notifications-admin-mutations on write paths
 *   - notifications-admin-reads     on the "lookup" reads
 */

// Audience admin (full CRUD scoped to this shop).
Route::prefix('notifications/audiences')
    ->name('api.v1.shops.notifications.audiences.')
    ->group(function () {
        Route::get('/', [ShopNotificationAudienceController::class, 'index'])->name('index');
        Route::post('/', [ShopNotificationAudienceController::class, 'store'])
            ->middleware('throttle:notifications-admin-mutations')
            ->name('store');
        Route::get('{id}', [ShopNotificationAudienceController::class, 'show'])->whereUuid('id')->name('show');
        Route::patch('{id}', [ShopNotificationAudienceController::class, 'update'])
            ->whereUuid('id')
            ->middleware('throttle:notifications-admin-mutations')
            ->name('update');
        Route::delete('{id}', [ShopNotificationAudienceController::class, 'destroy'])
            ->whereUuid('id')
            ->middleware('throttle:notifications-admin-mutations')
            ->name('destroy');
    });

// Template-override CRUD (shop wording trumps brand wording at render time).
Route::prefix('notifications/templates')
    ->name('api.v1.shops.notifications.templates.')
    ->group(function () {
        Route::get('/', [ShopNotificationTemplateController::class, 'index'])->name('index');
        Route::post('/', [ShopNotificationTemplateController::class, 'store'])
            ->middleware('throttle:notifications-admin-mutations')
            ->name('store');
        Route::patch('{id}', [ShopNotificationTemplateController::class, 'update'])
            ->whereUuid('id')
            ->middleware('throttle:notifications-admin-mutations')
            ->name('update');
        Route::delete('{id}', [ShopNotificationTemplateController::class, 'destroy'])
            ->whereUuid('id')
            ->middleware('throttle:notifications-admin-mutations')
            ->name('destroy');
    });

// Audit list — shop-scoped slice of the HQ audit feed.
Route::get('notifications', [ShopNotificationAdminController::class, 'index'])
    ->name('api.v1.shops.notifications.index');

// plan-023 M6 T6.9 — shop broadcast composer with shop-scoped resolver.
Route::post('notifications/broadcast', [ShopNotificationBroadcastController::class, 'store'])
    ->middleware('throttle:notifications-broadcast')
    ->name('api.v1.shops.notifications.broadcast');

// plan-023 M6 T6.8 — shop-scoped routing overrides.
Route::prefix('notifications/channel-routes')
    ->name('api.v1.shops.notifications.channel-routes.')
    ->group(function () {
        Route::get('/', [ShopNotificationChannelRouteController::class, 'index'])->name('index');
        Route::put('/', [ShopNotificationChannelRouteController::class, 'upsert'])
            ->middleware('throttle:notifications-admin-mutations')
            ->name('upsert');
        Route::delete('{type}', [ShopNotificationChannelRouteController::class, 'destroy'])
            ->where('type', '[a-zA-Z0-9._-]+')
            ->middleware('throttle:notifications-admin-mutations')
            ->name('destroy');
    });
