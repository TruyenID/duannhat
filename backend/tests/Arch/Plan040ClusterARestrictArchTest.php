<?php

/**
 * plan-040 Cluster A — RESTRICT FK integrity (C4 / M23 / M24).
 *
 * TESTS.md Cluster A demanded behavioural delete-block tests:
 *   (C4)  hard-deleting a genealogy parent/child lot is RESTRICT-blocked.
 *   (M23) a StockMovement / StockLevel row referencing a lot RESTRICT-blocks
 *         the lot delete (no SET NULL).
 *   (M24) a warehouse with StockAlert rows RESTRICT-blocks the warehouse delete
 *         (align with StockLevel/StockMovement — no CASCADE).
 *
 * ── WHY THIS IS A SCHEMA-SOURCE TEST, NOT A BEHAVIOURAL DELETE TEST ──
 *
 * The RESTRICT change is declared in the YAML source of truth
 * (schemas/Backend/Inventory/{GenealogyLink,StockMovement,StockLevel,StockAlert}.yaml
 *  → `onDelete: RESTRICT`) but the Omnify codegen NEVER PROPAGATED it into the
 * generated create migrations. The shipped
 * database/migrations/omnify/2000_01_01_*create_*_table.php still declare the
 * PRE-FIX behaviour:
 *
 *   • genealogy_links.parent_lot_id / child_lot_id → onDelete('CASCADE')  (want RESTRICT — C4)
 *   • stock_movements.material_lot_id               → onDelete('SET NULL') (want RESTRICT — M23)
 *   • stock_levels.material_lot_id                  → onDelete('SET NULL') (want RESTRICT — M23)
 *   • stock_alerts.warehouse_id                     → onDelete('CASCADE')  (want RESTRICT — M24)
 *
 * The plan-040 alter migrations (2000_02_20_*) only re-`->change()` the column
 * nullability/comment and add uniques — they do NOT drop+re-add the FK with the
 * new onDelete. So on production MySQL the constraint stays CASCADE/SET NULL,
 * and under the sqlite :memory: test driver the `->change()` table-rebuild
 * silently DROPS the FK entirely (verified empirically: deleting a parent lot
 * leaves its genealogy edge orphaned rather than blocking or cascading).
 *
 * Consequently a `expect(fn () => $lot->forceDelete())->toThrow(...)` behavioural
 * test CANNOT PASS today — the RESTRICT guarantee simply does not exist in the
 * shipped schema. Per the plan-audit rule ("if a test reveals a real product
 * bug, do NOT fix code — pin CURRENT behaviour + flag the bug"), this test:
 *
 *   1. Asserts the YAML intent IS recorded as RESTRICT (traceability for the
 *      three findings), and
 *   2. Pins the CURRENT shipped create-migration onDelete as the pre-fix value,
 *      so the moment codegen is fixed and the migration is regenerated this test
 *      goes red — forcing the maintainer to (a) update the expected value here
 *      and (b) land the real behavioural RESTRICT-blocks delete tests.
 *
 * ⚠️  PRODUCT BUG: C4/M23/M24 RESTRICT is UNSHIPPED. When codegen emits the FK
 *     change, replace the `currentOnDelete` expectations below with 'RESTRICT'
 *     and add behavioural delete-block Feature tests.
 *
 * (Pure filesystem scan — no DB.)
 */

/**
 * @return array{0:string,1:string,2:string,3:string,4:string} [migration, fkColumn, refTable, currentOnDelete, finding]
 */
dataset('cluster_a_restrict_fks', [
    'C4 genealogy parent_lot' => ['create_genealogy_links_table', 'parent_lot_id', 'material_lots', 'RESTRICT', 'C4'],
    'C4 genealogy child_lot' => ['create_genealogy_links_table', 'child_lot_id', 'material_lots', 'RESTRICT', 'C4'],
    'M23 stock_movement lot' => ['create_stock_movements_table', 'material_lot_id', 'material_lots', 'RESTRICT', 'M23'],
    'M23 stock_level lot' => ['create_stock_levels_table', 'material_lot_id', 'material_lots', 'RESTRICT', 'M23'],
    'M24 stock_alert warehouse' => ['create_stock_alerts_table', 'warehouse_id', 'warehouses', 'RESTRICT', 'M24'],
]);

/**
 * Resolve a generated migration by its TABLE STEM, never by filename.
 *
 * These datasets used to carry the full name including the sequence prefix
 * (`2000_01_01_000033_create_genealogy_links_table.php`). The #1217 renumbering
 * moved every file, `000033` now belongs to a different table entirely, and
 * every case here failed — silently, because tests/Arch was in no phpunit
 * testsuite and therefore never ran. Matching on the stem survives the next
 * renumbering, which is the only thing that made this brittle.
 */
function omnifyMigrationPath(string $tableStem): ?string
{
    $matches = glob(database_path('migrations/omnify/*_'.$tableStem.'.php'));

    return count($matches) === 1 ? $matches[0] : null;
}

/**
 * Extract the onDelete for a specific foreign() line in a generated migration.
 *
 * Named `archFkOnDelete` rather than `fkOnDelete`: Pest loads every test file
 * into ONE global scope, and tests/Feature/Inventory/InventoryFkRestrictTest
 * already declares that name. The collision was invisible while tests/Arch sat
 * outside every testsuite — wiring the directory in is what surfaced it.
 */
function archFkOnDelete(string $migrationBody, string $column, string $refTable): ?string
{
    $pattern = "/foreign\\('".preg_quote($column, '/')."'\\)->references\\('id'\\)->on\\('"
        .preg_quote($refTable, '/')."'\\)->onDelete\\('([^']+)'\\)/";

    return preg_match($pattern, $migrationBody, $m) ? $m[1] : null;
}

it('pins the shipped onDelete on the cluster-A foreign keys', function (
    string $migration,
    string $fkColumn,
    string $refTable,
    string $currentOnDelete,
    string $finding,
) {
    $path = omnifyMigrationPath($migration);
    expect($path)->not->toBeNull(
        "No generated migration matches *_{$migration}.php — the table was renamed or dropped."
    );

    $onDelete = archFkOnDelete((string) file_get_contents((string) $path), $fkColumn, $refTable);

    expect($onDelete)->not->toBeNull(
        "Could not locate the {$fkColumn} FK in {$migration} — the create migration shape changed."
    );

    // This began as a characterisation test pinning the PRE-fix values
    // (CASCADE / SET NULL) so that the day codegen emitted RESTRICT it would
    // fail and prompt the follow-up. Codegen has since emitted RESTRICT on all
    // five — and the prompt never arrived, because tests/Arch was in no phpunit
    // testsuite and this file had not run in a long time.
    //
    // The expectations now pin the shipped RESTRICT. STILL OUTSTANDING, and the
    // reason this comment stays: the behavioural delete-block tests the original
    // note asked for. Pinning the DDL proves codegen emits the clause; it does
    // not prove a delete is actually refused at runtime.
    expect($onDelete)->toBe(
        $currentOnDelete,
        "{$finding}: {$fkColumn} onDelete is now '{$onDelete}'. If it became 'RESTRICT' the fix "
        ."SHIPPED — update this expectation to 'RESTRICT' and add the behavioural delete-block test."
    );
})->with('cluster_a_restrict_fks');

it('records the RESTRICT intent in the YAML source of truth (C4/M23/M24)', function () {
    $schemaDir = base_path('../schemas/Backend/Inventory');

    // Backend may be checked out standalone (no sibling schemas/) — the
    // migration pin above is the authoritative guard; this is a bonus cross-check.
    if (! is_dir($schemaDir)) {
        expect(true)->toBeTrue();

        return;
    }

    $intents = [
        'GenealogyLink.yaml' => ['parent_lot', 'child_lot'],  // C4
        'StockMovement.yaml' => ['materialLot'],              // M23
        'StockLevel.yaml' => ['materialLot'],                 // M23
        'StockAlert.yaml' => ['warehouse'],                   // M24
    ];

    foreach ($intents as $file => $relations) {
        $yaml = file_get_contents($schemaDir.'/'.$file);
        foreach ($relations as $relation) {
            // Capture the first onDelete inside the relation's 4-space block.
            $pattern = '/\\n  '.preg_quote($relation, '/').':\\n(?:    .*\\n)*?    onDelete: (\\w+)/';
            expect(preg_match($pattern, $yaml, $m))->toBe(
                1,
                "Could not read onDelete for {$relation} in {$file}."
            );
            expect($m[1])->toBe(
                'RESTRICT',
                "{$file}:{$relation} must declare onDelete: RESTRICT (plan-040 C4/M23/M24 intent)."
            );
        }
    }
});
