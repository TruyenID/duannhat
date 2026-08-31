<?php

/**
 * Arch test — controllers must go through a Service; they must never call
 * `Model::create` / `Model::updateOrCreate` for Omnify-managed entities.
 * Service classes hold the transaction boundary, authorization scoping,
 * translatable wiring, and audit hooks — bypassing them loses all four.
 *
 * Allowed:
 *   - Importing the Model class for type-hinting route-bound args.
 *   - Writing via `$service->create(...)` / `$service->update(...)`.
 */
test('controllers do not call Model::create / Model::updateOrCreate for catalog models', function () {
    $controllerDir = app_path('Http/Controllers');
    $modelNames = ['Allergen', 'Material', 'Product', 'Recipe'];

    $offenders = [];

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllerDir));
    foreach ($rii as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        foreach ($modelNames as $model) {
            foreach (['create', 'updateOrCreate'] as $method) {
                if (preg_match('/\b'.$model.'::'.$method.'\s*\(/', $contents)) {
                    $offenders[] = $file->getPathname().' → '.$model.'::'.$method;
                }
            }
        }
    }

    expect($offenders)->toBe([], 'Catalog controllers must go through a Service. Offenders: '.implode(', ', $offenders));
});
