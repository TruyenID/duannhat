<?php

declare(strict_types=1);

/**
 * #1274 — omnify emitted a migration that created a column, its foreign key and
 * its index (each behind a `Schema::has…` guard) and then dropped that same
 * column on the last line, unguarded:
 *
 *     if (! Schema::hasColumn('customer_orders', 'table_session_id')) { … }
 *     if (! Schema::hasForeignKey(…)) { … }
 *     if (! Schema::hasIndex(…))     { … }
 *     $table->dropColumn('table_session_id');   // no guard
 *
 * It reads as a careful migration for three lines. Running it would have removed
 * every dine-in order's link to its table session. `omnify diff` showed only
 * "+ Added property / - Removed property" and no DDL, so nothing before this
 * point would have caught it.
 *
 * The rule: a generated `up()` may not destroy structure without asking whether
 * it exists. `down()` is exempt — a reversal drops what its own `up()` created,
 * and every current instance of an unguarded drop is there.
 *
 * This is the third silent-generator defect on record (CLAUDE.md documents two
 * more), so the gate is on the shape rather than on this one migration.
 */
it('never destroys structure in a generated up() without a guard', function () {
    $files = glob(base_path('database/migrations/omnify/*.php')) ?: [];

    // A glob that matched nothing would pass while checking nothing — the shape
    // of failure this file exists to catch, one level up.
    expect(count($files))->toBeGreaterThan(100, 'almost no generated migrations found — the scan is broken');

    $violations = [];

    foreach ($files as $file) {
        $lines = file($file) ?: [];

        $downAt = null;
        foreach ($lines as $number => $line) {
            if (str_contains($line, 'function down(')) {
                $downAt = $number;
                break;
            }
        }

        foreach ($lines as $number => $line) {
            // down() reverses its own up(); dropping there is the point.
            if ($downAt !== null && $number > $downAt) {
                continue;
            }
            if (preg_match('/\$table->(dropColumn|dropForeign|dropIndex|dropUnique|dropPrimary)\(/', $line, $m) !== 1) {
                continue;
            }

            // The guard sits on one of the few lines above, as `if (Schema::has…`
            // or `if (! Schema::has…`.
            $context = implode('', array_slice($lines, max(0, $number - 4), min(4, $number)));
            if (preg_match('/if \(\s*!?\s*Schema::has(Column|Index|Table|ForeignKey)/', $context) === 1) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d — %s without a Schema::has… guard',
                basename($file),
                $number + 1,
                $m[1],
            );
        }
    }

    expect($violations)->toBe([], implode("\n  ", [
        'A generated up() destroys structure without checking whether it exists.',
        'omnify has emitted exactly this next to guarded creates for the same column (#1274),',
        'which reads as safe and removes production data:',
        ...$violations,
    ]));
});
