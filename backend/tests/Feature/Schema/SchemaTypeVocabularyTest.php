<?php

declare(strict_types=1);

/**
 * #1263 — omnify answers an unrecognised `type:` by falling back to String,
 * without an error. Five rating columns were declared `TinyInteger` and built as
 * `varchar(255)`. The generated PHPDoc said `@property mixed`, the TypeScript
 * said `string`, the editable model cast `integer`, and `->avg('rating')` ran
 * against a text column. The supported spelling is `TinyInt`, used correctly
 * three fields away in the same directory.
 *
 * A one-word typo, invisible because the fallback is silent. This is the third
 * defect of that shape on record — CLAUDE.md documents two more.
 *
 * Checked against the YAML rather than the database on purpose: the test suite
 * runs on SQLite, which has no real column types, so a database comparison
 * would skip in CI and guard nothing. This runs everywhere.
 *
 * A denylist, not an allowlist: a genuinely new supported type must not fail
 * the build, but these specific spellings are known to be silently wrong.
 */
it('uses no schema type spelling that omnify silently downgrades to String', function () {
    // Longhand forms omnify does not know. Each has a supported short form.
    $unsupported = [
        'Integer' => 'Int',
        'TinyInteger' => 'TinyInt',
        'SmallInteger' => 'SmallInt',
        'MediumInteger' => 'MediumInt',
        'BigInteger' => 'BigInt',
        'Bool' => 'Boolean',
        'Number' => 'Int or Decimal',
    ];

    $schemaRoot = base_path('../schemas');
    if (! is_dir($schemaRoot)) {
        $this->markTestSkipped('schemas/ is not present in this checkout');
    }

    $violations = [];
    $scanned = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($schemaRoot));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'yaml') {
            continue;
        }

        $scanned++;
        $relative = str_replace(base_path('..').DIRECTORY_SEPARATOR, '', $file->getPathname());

        foreach (file($file->getPathname()) ?: [] as $number => $line) {
            if (preg_match('/^\s*type:\s*([A-Za-z]+)\s*$/', $line, $m) !== 1) {
                continue;
            }

            $declared = $m[1];
            if (isset($unsupported[$declared])) {
                $violations[] = sprintf(
                    '%s:%d — type: %s (omnify does not know it; use %s)',
                    $relative,
                    $number + 1,
                    $declared,
                    $unsupported[$declared],
                );
            }
        }
    }

    // A scan that reads nothing passes silently — the exact failure this file
    // exists to catch, one level up.
    expect($scanned)->toBeGreaterThan(50, 'almost no schema files were read — the scan is broken, not the schemas');

    expect($violations)->toBe([], implode("\n  ", [
        'These declare a type omnify does not recognise. It falls back to String without an error,',
        'so the column ships as varchar while the model, the PHPDoc and the TS types disagree:',
        ...$violations,
    ]));
});
