<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * #1195 — the domain-mutation guard must fail CLOSED on tables it has never
 * heard of.
 *
 * `config/domain-mutation-guard.php` lists each aggregate's tables by hand, and
 * the guard only inspects what is listed. That made "forgot to register the new
 * table" indistinguishable from "table is deliberately unguarded": both are
 * silent, and CI stays green. It bit twice for real — #1194 found
 * `floating_section_product_topping_item_overrides` (shop topping prices) and
 * `floating_section_translations` outside every aggregate, and opening that
 * blind spot immediately exposed two direct writers nobody had ever reviewed.
 *
 * The invariant asserted here bridges the gap through the schema itself:
 *
 *   a table holding a foreign key into a DECLARED aggregate's table must
 *   itself belong to SOME aggregate — or appear in `fk_reachability_exempt`
 *   with a stated reason.
 *
 * Writing rows that reference an aggregate's rows puts you inside that
 * aggregate's consistency boundary. It need not be the SAME aggregate (an
 * invoice references an order and is its own), only a declared one.
 */
function fkReachableUnguardedTables(array $declaredTables, array $exempt): array
{
    $violations = [];

    foreach (Schema::getTables() as $table) {
        $name = $table['name'];

        if (in_array($name, $declaredTables, true) || array_key_exists($name, $exempt)) {
            continue;
        }

        $reached = [];
        foreach (Schema::getForeignKeys($name) as $foreignKey) {
            if (in_array($foreignKey['foreign_table'], $declaredTables, true)) {
                $reached[] = $foreignKey['foreign_table'];
            }
        }

        if ($reached !== []) {
            $violations[$name] = array_values(array_unique($reached));
        }
    }

    return $violations;
}

/** @return array<int, string> every table named by an aggregate */
function declaredAggregateTables(): array
{
    return collect(config('domain-mutation-guard.aggregates'))
        ->flatMap(fn (array $aggregate): array => $aggregate['tables'])
        ->unique()
        ->values()
        ->all();
}

it('leaves no table that references a guarded aggregate outside every aggregate', function () {
    $violations = fkReachableUnguardedTables(
        declaredAggregateTables(),
        config('domain-mutation-guard.fk_reachability_exempt'),
    );

    $report = collect($violations)
        ->map(fn (array $targets, string $table): string => sprintf(
            '  %s → references %s',
            $table,
            implode(', ', $targets),
        ))
        ->implode("\n");

    expect($violations)->toBe([], <<<TXT
        These tables hold a foreign key into a guarded aggregate but belong to no aggregate,
        so every writer of them is invisible to the mutation guard:

        {$report}

        Fix it one of two ways:
          1. Add the table (and its model) to the aggregate that owns its consistency —
             then run `php artisan architecture:domain-writers --json` and declare the
             writers it reports as boundaries.
          2. If the rows genuinely have their own lifecycle, add the table to
             `fk_reachability_exempt` in config/domain-mutation-guard.php WITH a reason.
        TXT);
});

it('keeps the exemption list honest — no stale or reasonless entries', function () {
    $exempt = config('domain-mutation-guard.fk_reachability_exempt');
    $declared = declaredAggregateTables();
    $existing = array_column(Schema::getTables(), 'name');

    foreach ($exempt as $table => $reason) {
        expect($table)->toBeString('An exemption must be keyed by table name, with the reason as the value.')
            ->and($reason)->toBeString()
            ->and(strlen(trim((string) $reason)))->toBeGreaterThan(20, "Exemption for [{$table}] needs a real reason, not a placeholder.")
            ->and(in_array($table, $existing, true))
            ->toBeTrue("Exempted table [{$table}] no longer exists — delete the exemption.")
            ->and(in_array($table, $declared, true))
            ->toBeFalse("Table [{$table}] is now declared in an aggregate — delete the exemption.");
    }
});

it('actually catches a table dropped out of its aggregate — the #1194 regression', function () {
    // Both tables were the real miss in #1194. Pretend they were never
    // registered and the check must name them; a green result here would mean
    // the invariant is vacuous.
    $withoutThePatch = array_values(array_diff(
        declaredAggregateTables(),
        ['floating_section_product_topping_item_overrides', 'floating_section_translations'],
    ));

    $violations = fkReachableUnguardedTables(
        $withoutThePatch,
        config('domain-mutation-guard.fk_reachability_exempt'),
    );

    expect($violations)->toHaveKey('floating_section_product_topping_item_overrides')
        ->and($violations)->toHaveKey('floating_section_translations')
        ->and($violations['floating_section_translations'])->toContain('floating_sections');
});
