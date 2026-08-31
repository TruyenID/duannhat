<?php

use App\Http\Controllers\Api\V1\Shop\ShopTillStatusController;
use App\Http\Controllers\Api\V1\Shop\ShopTillTenderActivationController;
use App\Http\Controllers\Api\V1\Shop\ShopTillTrackingController;
use App\Models\TillSession;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — till (cashier shift) read-only status + plan-036
 * Manager Till Tracking surface for admin-web + #1156 per-branch tender
 * activation.
 *
 *   GET /shops/{shopSlug}/till/current        — current open session
 *   GET /shops/{shopSlug}/till/dashboard      — plan-036 manager KPIs (polled 5s)
 *   GET /shops/{shopSlug}/till/sessions       — plan-036 paginated history (CSV w/ ?export=csv)
 *   GET /shops/{shopSlug}/till/sessions/{id}  — plan-036 session detail
 *   GET /shops/{shopSlug}/till/sessions/{id}/z-report.pdf — plan-036 PDF
 *   GET   /shops/{shopSlug}/till/tender-types             — #1156 effective tender list
 *   PATCH /shops/{shopSlug}/till/tender-types/{tenderKey} — #1156 branch activation switch
 *
 * Shift state itself is read-only here by design. All shift mutations
 * (open/close/abandon/draft/cash-event/force-abandon/manual-settle) live
 * under /pos/ for the POS device session, not here. The tender-activation
 * PATCH mutates configuration (a per-branch override row), never shift
 * state.
 */

// Explicit binding by the {session} param — implicit binding mis-resolves
// because admin-web does not include the parent shop slug in the cache key.
Route::bind('session', fn (string $value) => TillSession::query()->findOrFail($value));

Route::prefix('till')
    ->name('api.v1.shops.till.')
    ->group(function () {
        Route::get('current', [ShopTillStatusController::class, 'current'])->name('current');

        // #1156 — per-branch tender activation (effective list + switch).
        Route::get('tender-types', [ShopTillTenderActivationController::class, 'index'])
            ->name('tender-types.index');
        Route::patch('tender-types/{tenderKey}', [ShopTillTenderActivationController::class, 'update'])
            ->name('tender-types.update');

        // Plan-036 — manager tracking surface.
        Route::get('dashboard', [ShopTillTrackingController::class, 'dashboard'])
            ->name('dashboard');
        Route::get('sessions', [ShopTillTrackingController::class, 'sessions'])
            ->name('sessions.index');
        Route::get('sessions/{session}', [ShopTillTrackingController::class, 'session'])
            ->name('sessions.show');
        Route::get('sessions/{session}/z-report.pdf', [ShopTillTrackingController::class, 'zReport'])
            ->name('sessions.zReport');
    });
