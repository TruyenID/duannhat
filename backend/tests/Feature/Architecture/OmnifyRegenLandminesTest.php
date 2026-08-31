<?php

declare(strict_types=1);

/**
 * #1314 — guard the facts that `omnify generate` SILENTLY REVERTS.
 *
 * Running the generator on 5.9.18 rewrites ~650 generated files, but almost all
 * of that is churn that `vendor/bin/pint --dirty` puts straight back (the
 * generator emits `static::CONST`, pint's self_static_accessor rewrites it to
 * `self::`). After pint, a handful of files still differ — and two of those
 * carry decisions that must NOT be lost:
 *
 *   1. InvoiceCounter          — the table has NO created_at column
 *   2. OmnifyServiceProvider   — morphMap, deliberately NOT enforceMorphMap
 *
 * Cho tới #2041 còn một mục thứ ba, `OrderAdjustmentAllocation` (bảng không có
 * `updated_at`). plan-049 đã bị gỡ hoàn toàn nên bãi mìn đó không còn đất để nổ.
 *
 * Each is load-bearing, and losing it fails at RUNTIME rather than at codegen:
 * an insert writing a column that does not exist, or a morph type that throws
 * because it is not in the map. Nothing in the generator's own output warns you.
 *
 * A FOURTH landmine was found on 2026-08-02 (#1637), and it is worse than the
 * three above because it is not a silent runtime fault — it is a hard PARSE
 * error, so the class cannot be autoloaded at all:
 *
 *   4. UserPolicyBase — the generator emits `use App\Models\User;` TWICE.
 *      `Cannot use App\Models\User as User because the name is already in use`.
 *
 * It fires on the `User` entity because the policy imports the model both as the
 * policy's subject and as the acting user, and the generator does not dedupe the
 * import list. HEAD parses; a regen breaks it; pint does not fix it (a duplicate
 * import is valid style, just invalid PHP). The fix is to delete the second copy
 * of the line — which puts the file back byte-for-byte to HEAD.
 *
 * This test does not stop the generator from reverting them — nothing can. It
 * makes the revert LOUD: regen, run pint, run this file, and a red test tells
 * you which decision to restore before you commit.
 *
 * Read CLAUDE.md → "lỗi generator đã biết" (items 3-5) and #1314 for the full
 * list, including the two findings this test cannot express: the generator
 * writes *ServiceBase.php for 111 modules despite `service: enable: false` (they
 * land UNTRACKED, so `git add -A` after a regen commits all 111 directories),
 * and .omnify/lock.json re-serialises its arrays in a different order every run
 * (so a no-op regen still shows a diff — revert it).
 */

use App\Models\InvoiceCounter;
use Illuminate\Support\Facades\Schema;

/**
 * A model whose table lacks created_at/updated_at MUST null out the matching
 * Eloquent constant, or every insert tries to write a column that is not there.
 *
 * @return array<string, array{class-string, string, string}>
 */
dataset('models missing a timestamp column', [
    'InvoiceCounter has no created_at' => [
        InvoiceCounter::class,
        'created_at',
        'CREATED_AT',
    ],
]);

it('nulls the Eloquent timestamp constant for a column the table does not have',
    function (string $modelClass, string $column, string $constant) {
        $model = new $modelClass;
        $table = $model->getTable();

        // Guard the premise first: if the column ever gets added, this test is
        // telling you the wrong thing and should be revisited, not deleted.
        expect(Schema::hasColumn($table, $column))
            ->toBeFalse("`{$table}` gained a `{$column}` column — revisit #1314 instead of relaxing this test.");

        expect(constant($modelClass.'::'.$constant))
            ->toBeNull(
                "{$modelClass}::{$constant} must be null because `{$table}` has no `{$column}`. "
                .'`omnify generate` DELETES this constant; pint does not put it back. '
                .'Restore it in the Omnify base model before committing a regen (#1314).'
            );
    }
)->with('models missing a timestamp column');

it('registers the Omnify morph map with morphMap, not enforceMorphMap', function () {
    // enforceMorphMap REQUIRES every morphable class to be listed, and throws for
    // anything absent — Sanctum tokenables, media, notifications. The generated
    // provider was hand-changed to morphMap for exactly that reason, with a
    // comment saying so, and `omnify generate` reverts BOTH the call and the
    // comment on every run. The file's own header says DO NOT EDIT, which is
    // precisely why this deviation needs a test to defend it.
    $source = file_get_contents(app_path('Providers/OmnifyServiceProvider.php'));

    expect($source)
        ->toContain('Relation::morphMap(')
        ->not->toContain('Relation::enforceMorphMap(');
});

it('emits no generated file with a duplicate import', function () {
    // #1637. `php -l` over all 1022 generated files is the strongest form of this
    // check but costs ~35s, which is too slow for an architecture test that people
    // run on every regen. A duplicate `use` is the one syntax error the generator
    // is known to produce, and scanning for it in-process costs milliseconds — so
    // this guard is narrow on purpose. If the generator ever breaks syntax some
    // OTHER way, run the full sweep by hand:
    //
    //   for f in $(find app/Omnify -name '*.php'); do php -l "$f" >/dev/null || echo "$f"; done
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Omnify'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Only top-level import statements — `use` inside a closure or a trait
        // block is a different construct and may legitimately repeat.
        preg_match_all(
            '/^use\s+[^;]+;$/m',
            (string) file_get_contents($file->getPathname()),
            $matches
        );

        $imports = $matches[0];

        if (count($imports) !== count(array_unique($imports))) {
            $duplicated = array_unique(array_diff_assoc($imports, array_unique($imports)));
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname())
                .' → '.implode(', ', $duplicated);
        }
    }

    expect($offenders)->toBe([],
        "These generated files carry a duplicate import, which is a FATAL parse error:\n"
        .implode("\n", $offenders)
        ."\n\n`omnify generate` writes them that way (known: UserPolicyBase gets "
        .'`use App\Models\User;` twice) and pint does not fix it. Delete the extra '
        .'line before committing the regen (#1637).'
    );
});
