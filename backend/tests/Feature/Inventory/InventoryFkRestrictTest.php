<?php

use Illuminate\Support\Facades\DB;

/**
 * plan-040 audit C4 / M23 / M24 — FK RESTRICT integrity.
 *
 * The source-of-truth YAML documents onDelete: RESTRICT on the genealogy edges
 * and the append-only stock ledger, but the shipped create-table migrations
 * drifted (CASCADE / SET NULL). These assertions pin the DB back to the schema
 * so a hard delete can never silently destroy the FSMA-204 recall chain or the
 * stock ledger, and so a future omnify reset that re-drifts is caught in CI.
 */

/**
 * Read a foreign key's ON DELETE referential action, cross-driver.
 *
 * @return string one of RESTRICT | CASCADE | SET NULL | NO ACTION
 */
function fkOnDelete(string $table, string $column): string
{
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        foreach (DB::select("PRAGMA foreign_key_list({$table})") as $fk) {
            if ($fk->from === $column) {
                return strtoupper($fk->on_delete);
            }
        }

        return 'MISSING';
    }

    // mysql / mariadb
    $row = DB::selectOne(
        'SELECT rc.DELETE_RULE AS delete_rule
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         JOIN information_schema.KEY_COLUMN_USAGE kcu
           ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
          AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
         WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
           AND kcu.TABLE_NAME = ?
           AND kcu.COLUMN_NAME = ?
         LIMIT 1',
        [$table, $column]
    );

    return $row ? strtoupper($row->delete_rule) : 'MISSING';
}

it('declares RESTRICT on every drifted inventory FK', function (string $table, string $column) {
    expect(fkOnDelete($table, $column))->toBe('RESTRICT');
})->with([
    'genealogy parent edge' => ['genealogy_links', 'parent_lot_id'],
    'genealogy child edge' => ['genealogy_links', 'child_lot_id'],
    'stock ledger lot ref' => ['stock_movements', 'material_lot_id'],
    'stock level lot ref' => ['stock_levels', 'material_lot_id'],
    'stock alert warehouse ref' => ['stock_alerts', 'warehouse_id'],
]);
