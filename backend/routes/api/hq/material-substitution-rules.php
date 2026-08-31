<?php

use App\Http\Controllers\Api\V1\HQ\MaterialSubstitutionRuleController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Material Substitution Rules
//  Per-material substitute fallback rules surfaced on the HQ material
//  detail page. Mounted under /api/v1/hq/{brandSlug}/...
// =========================================================================

Route::prefix('material-substitution-rules')
    ->name('api.v1.hq.material-substitution-rules.')
    ->group(function () {
        Route::get('/', [MaterialSubstitutionRuleController::class, 'index'])->name('index');
        Route::post('/', [MaterialSubstitutionRuleController::class, 'store'])->name('store');
        Route::get('{rule}', [MaterialSubstitutionRuleController::class, 'show'])->name('show');
        Route::put('{rule}', [MaterialSubstitutionRuleController::class, 'update'])->name('update');
        Route::delete('{rule}', [MaterialSubstitutionRuleController::class, 'destroy'])->name('destroy');
    });
