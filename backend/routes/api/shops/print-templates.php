<?php

use App\Http\Controllers\Api\V1\Shop\ShopPrintTemplateController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — print template OVERRIDES (plan-053 #1171, layer 2).
 *
 *   GET  /shops/{shopSlug}/print-templates                 per-kind state
 *   GET  /shops/{shopSlug}/print-templates/{kind}          resolved slip + allow-list
 *   POST /shops/{shopSlug}/print-templates/{kind}/draft
 *   POST /shops/{shopSlug}/print-templates/{kind}/publish
 *
 * A shop edits only what its brand delegated via `shop_editable`; everything
 * else is the brand's (DESIGN §1). Requires `shop.manage` — a cashier does
 * not see this surface at all (TR-37).
 */

Route::prefix('print-templates')
    ->name('api.v1.shops.print_templates.')
    ->group(function () {
        Route::get('/', [ShopPrintTemplateController::class, 'index'])->name('index');
        Route::get('{kind}', [ShopPrintTemplateController::class, 'show'])->name('show');
        // M5 (TR-32) — SVG preview of the RESOLVED slip this shop prints.
        // POST renders a definition from the body (the editor's unsaved state);
        // it is the same read, and stores nothing.
        Route::match(['get', 'post'], '{kind}/preview', [ShopPrintTemplateController::class, 'preview'])
            ->name('preview');
        Route::post('{kind}/draft', [ShopPrintTemplateController::class, 'saveDraft'])->name('draft');
        Route::post('{kind}/publish', [ShopPrintTemplateController::class, 'publish'])->name('publish');
    });
