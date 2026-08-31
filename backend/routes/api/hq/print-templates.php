<?php

use App\Http\Controllers\Api\V1\HQ\PrintTemplateController;
use App\Models\PrintTemplate;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  HQ — Print templates (plan-053 #1171, brand layer)
//
//  A brand authors its slips here and publishes them as immutable versions;
//  every workstation of the brand picks them up on its next pull, with no
//  software release. Reads need `menu.manage`, writes need `catalog.approve`
//  (TR-37) — see PrintTemplatePolicy.
//
//  There is deliberately NO delete: a published version has to stay
//  renderable forever so a reprint of an old job is truthful (TR-28/TR-39).
//  The exits are `retire` (out of service for new prints) and `rollback`
//  (republish an older definition as a NEW version — never an un-publish).
// =========================================================================

// Bound explicitly: the version routes address a template by its own id while
// the parent {kind} segment is a plain string, which implicit binding would
// try to resolve as a model parameter.
Route::bind('printTemplate', fn (string $value) => PrintTemplate::query()->findOrFail($value));

Route::prefix('print-templates')->name('api.v1.hq.print_templates.')->group(function () {
    Route::get('/', [PrintTemplateController::class, 'index'])->name('index');

    Route::prefix('{kind}')->group(function () {
        Route::get('/', [PrintTemplateController::class, 'show'])->name('show');
        Route::get('history', [PrintTemplateController::class, 'history'])->name('history');
        Route::get('diff', [PrintTemplateController::class, 'diff'])->name('diff');
        // M5 (TR-32) — SVG preview. A READ of the brand's own draft, so it
        // rides `menu.manage` like the rest of the read surface; publishing is
        // still `catalog.approve`.
        //
        // POST is the same READ: it renders a definition supplied in the body
        // (the editor's unsaved state) instead of looking one up. A verb, not a
        // side effect — GET cannot carry a document, and previewing by saving
        // first would 409 the other tab open on the same draft (TR-09).
        Route::match(['get', 'post'], 'preview', [PrintTemplateController::class, 'preview'])
            ->name('preview');

        Route::post('draft', [PrintTemplateController::class, 'saveDraft'])->name('draft');
        Route::post('publish', [PrintTemplateController::class, 'publish'])->name('publish');

        Route::patch('versions/{printTemplate}', [PrintTemplateController::class, 'updateVersion'])
            ->name('versions.update');
        Route::post('versions/{printTemplate}/retire', [PrintTemplateController::class, 'retire'])
            ->name('versions.retire');
        Route::post('versions/{printTemplate}/rollback', [PrintTemplateController::class, 'rollback'])
            ->name('versions.rollback');
    });
});
