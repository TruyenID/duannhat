<?php

/**
 * Plan 017 — Arch invariants for the lot-tracking domain.
 *
 * 1. GenealogyLinkService is APPEND-ONLY. The only public methods it
 *    exposes must start with `record` (recordProductionConsumption,
 *    recordSalesConsumption, recordDisposal, recordManualAdjustment).
 *    No update / delete / save / write methods — any mutation of an
 *    existing genealogy_links row would break FSMA-204 retention
 *    (compensating writes happen via recordManualAdjustment instead).
 *
 * 2. genealogy_links must NEVER have UPDATE statements compiled
 *    against it from any service. The append-only contract is
 *    enforced by both the service surface (rule 1) AND by an
 *    arch-level grep over the runtime PHP that prevents accidental
 *    raw `->update(` calls on a GenealogyLink instance.
 */

use App\Services\Inventory\GenealogyLinkService;

test('GenealogyLinkService exposes only record* methods', function () {
    $reflection = new ReflectionClass(GenealogyLinkService::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method) => $method->isConstructor())
        ->reject(fn (ReflectionMethod $method) => $method->getDeclaringClass()->getName() !== GenealogyLinkService::class)
        ->map(fn (ReflectionMethod $method) => $method->getName())
        ->all();

    foreach ($publicMethods as $name) {
        expect($name)->toStartWith('record', "Public method `{$name}` violates append-only invariant");
    }

    expect($publicMethods)->toContain('recordProductionConsumption')
        ->toContain('recordSalesConsumption')
        ->toContain('recordDisposal')
        ->toContain('recordManualAdjustment');
});

test('GenealogyLink model is never updated outside compensating writes', function () {
    // Search for any `GenealogyLink::*->update(` or `App\Models\GenealogyLink::*->update(`
    // pattern in app/. The model itself never owns ->update() calls on its instances —
    // corrections happen via new rows.
    $offenders = [];

    foreach (['app/Services', 'app/Http'] as $dir) {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));
        foreach ($rii as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());

            // Match `GenealogyLink::...->update(` OR `genealogyLink->update(` (instance).
            if (preg_match('/GenealogyLink[^;{]*->update\(/', $contents)
                || preg_match('/genealogyLink->update\(/', $contents)
                || preg_match('/GenealogyLink::\w*Update/', $contents)
            ) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([], 'GenealogyLink rows must never be ->update()d. Compensating writes use recordManualAdjustment().');
});
