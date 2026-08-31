<?php

namespace App\Support\Schema;

use Illuminate\Database\Connection;

/**
 * #1216 — a normalised, comparable picture of one MySQL schema.
 *
 * Read from `information_schema` rather than from a `mysqldump`, because the
 * dump is a rendering: it carries column ORDER, `COMMENT`, `AUTO_INCREMENT=`
 * counters and charset clauses that differ between two databases holding the
 * same schema. Comparing renderings produces noise that buries the two or
 * three findings that matter.
 *
 * What is deliberately NOT captured, and why:
 *
 *   - column order — MySQL keeps the order columns were added in, so a column
 *     added by an ALTER lands last on a migrated database and mid-table on a
 *     fresh one. Never a behaviour difference.
 *   - `COLUMN_COMMENT` — documentation.
 *   - table options (engine, charset, collation at table level) — uniform here,
 *     and a mismatch would show up on the columns anyway.
 *   - VIEWs — `information_schema.COLUMNS` lists them alongside base tables, so
 *     without the TABLE_TYPE filter a view created by hand on the live database
 *     would be reported as `table.extra` DRIFT on every single run, forever: no
 *     migration creates it, and none ever will. Migrations build base tables;
 *     that is what this compares. (There are no views in the schema today —
 *     this keeps a hand-made one on some future deployment from turning the
 *     exit code permanently red.)
 *
 * Column collation IS captured: a column that is `utf8mb4_bin` on one side and
 * `utf8mb4_unicode_ci` on the other compares strings differently, which is a
 * behaviour difference and exactly the kind that hides.
 */
final class SchemaSnapshot
{
    /**
     * @param  array<string, array{columns: array<string, string>, indexes: array<string, array{unique: bool, columns: list<string>}>, foreign_keys: array<string, array{columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_delete: string, on_update: string}>}>  $tables
     */
    public function __construct(
        public readonly string $database,
        public readonly array $tables,
    ) {}

    public static function read(Connection $connection, string $database): self
    {
        $tables = [];

        foreach ($connection->select(
            'SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c, c.COLUMN_TYPE AS type, c.IS_NULLABLE AS nullable,
                    c.COLUMN_DEFAULT AS `default`, c.EXTRA AS extra, c.COLLATION_NAME AS collation
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t
               ON t.TABLE_SCHEMA = c.TABLE_SCHEMA
              AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = ?
               AND t.TABLE_TYPE = ?',
            [$database, 'BASE TABLE']
        ) as $row) {
            $tables[$row->t]['columns'][$row->c] = self::describeColumn($row);
        }

        $indexColumns = [];
        foreach ($connection->select(
            'SELECT TABLE_NAME AS t, INDEX_NAME AS name, NON_UNIQUE AS non_unique,
                    SEQ_IN_INDEX AS seq, COLUMN_NAME AS c, SUB_PART AS sub_part
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
             ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            [$database]
        ) as $row) {
            $column = $row->c ?? '(expression)';
            if ($row->sub_part !== null) {
                $column .= '('.$row->sub_part.')';
            }
            $indexColumns[$row->t][$row->name]['unique'] = ((int) $row->non_unique) === 0;
            $indexColumns[$row->t][$row->name]['columns'][] = $column;
        }
        foreach ($indexColumns as $table => $indexes) {
            $tables[$table]['indexes'] = $indexes;
        }

        $foreignKeys = [];
        foreach ($connection->select(
            'SELECT rc.TABLE_NAME AS t, rc.CONSTRAINT_NAME AS name, rc.UPDATE_RULE AS on_update,
                    rc.DELETE_RULE AS on_delete, rc.REFERENCED_TABLE_NAME AS ref_table,
                    kcu.COLUMN_NAME AS c, kcu.REFERENCED_COLUMN_NAME AS ref_column
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE kcu
               ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
              AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
              AND kcu.TABLE_NAME = rc.TABLE_NAME
             WHERE rc.CONSTRAINT_SCHEMA = ?
             ORDER BY rc.TABLE_NAME, rc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION',
            [$database]
        ) as $row) {
            $foreignKeys[$row->t][$row->name]['referenced_table'] = $row->ref_table;
            $foreignKeys[$row->t][$row->name]['on_delete'] = $row->on_delete;
            $foreignKeys[$row->t][$row->name]['on_update'] = $row->on_update;
            $foreignKeys[$row->t][$row->name]['columns'][] = $row->c;
            $foreignKeys[$row->t][$row->name]['referenced_columns'][] = $row->ref_column;
        }
        foreach ($foreignKeys as $table => $constraints) {
            $tables[$table]['foreign_keys'] = $constraints;
        }

        foreach ($tables as $table => $parts) {
            $tables[$table] = [
                'columns' => $parts['columns'] ?? [],
                'indexes' => $parts['indexes'] ?? [],
                'foreign_keys' => $parts['foreign_keys'] ?? [],
            ];
        }
        ksort($tables);

        return new self($database, $tables);
    }

    /**
     * One column rendered as a single comparable string.
     *
     * `DEFAULT_GENERATED` is stripped from EXTRA: MySQL 8 sets it on any column
     * whose default is an expression, and 5.7-era servers do not, so keeping it
     * would report every such column as drift between two identical schemas.
     */
    private static function describeColumn(object $row): string
    {
        $parts = [$row->type];
        $parts[] = $row->nullable === 'YES' ? 'NULL' : 'NOT NULL';

        if ($row->default !== null) {
            $parts[] = 'DEFAULT '.$row->default;
        }

        $extra = trim(str_ireplace('DEFAULT_GENERATED', '', (string) $row->extra));
        if ($extra !== '') {
            $parts[] = strtolower($extra);
        }

        if ($row->collation !== null) {
            $parts[] = 'COLLATE '.$row->collation;
        }

        return implode(' ', $parts);
    }
}
