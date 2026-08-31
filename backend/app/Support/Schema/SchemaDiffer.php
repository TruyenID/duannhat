<?php

namespace App\Support\Schema;

/**
 * #1216 — compare an EXPECTED schema (one built from scratch by the current
 * migrations) against an ACTUAL one (a database that has been migrating for
 * months). Pure: it takes two normalised snapshots and returns findings, so it
 * is testable without a MySQL server.
 *
 * Every finding is worded from the live database's point of view, because that
 * is the thing a human is deciding whether to touch.
 *
 * Two severities, and the split is the whole point of the tool:
 *
 *   - DRIFT — the two databases behave differently. A missing index turns a hot
 *     query into a full scan; a missing foreign key lets orphans in; a narrower
 *     column truncates; a different referential action changes what a delete
 *     does. These are the ones worth a migration.
 *   - COSMETIC — the same constraint under a different name. Harmless right up
 *     until someone writes `dropIndex('<name>')`, which then runs on exactly
 *     one of the two shapes. Reported, never a failure.
 *
 * Indexes and foreign keys are matched by SHAPE, not by name, precisely so a
 * naming difference does not masquerade as a missing constraint — the failure
 * mode that made the first pass at this comparison unreadable.
 */
final class SchemaDiffer
{
    public const DRIFT = 'drift';

    public const COSMETIC = 'cosmetic';

    /**
     * @param  array<string, array{columns: array<string, string>, indexes: array<string, array{unique: bool, columns: list<string>}>, foreign_keys: array<string, array{columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_delete: string, on_update: string}>}>  $expected
     * @param  array<string, array{columns: array<string, string>, indexes: array<string, array{unique: bool, columns: list<string>}>, foreign_keys: array<string, array{columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_delete: string, on_update: string}>}>  $actual
     * @return list<array{table: string, severity: string, kind: string, detail: string}>
     */
    public function compare(array $expected, array $actual, ?callable $ignore = null): array
    {
        $findings = [];

        foreach (array_diff(array_keys($expected), array_keys($actual)) as $table) {
            $findings[] = $this->finding($table, self::DRIFT, 'table.missing', 'table does not exist on the live database');
        }
        foreach (array_diff(array_keys($actual), array_keys($expected)) as $table) {
            $findings[] = $this->finding($table, self::DRIFT, 'table.extra', 'table exists on the live database but no migration creates it');
        }

        foreach (array_intersect(array_keys($expected), array_keys($actual)) as $table) {
            array_push($findings, ...$this->compareTable($table, $expected[$table], $actual[$table]));
        }

        usort($findings, fn (array $a, array $b): int => [$a['table'], $a['kind'], $a['detail']] <=> [$b['table'], $b['kind'], $b['detail']]);

        if ($ignore !== null) {
            $findings = array_values(array_filter($findings, fn (array $f): bool => ! $ignore($f)));
        }

        return $findings;
    }

    /**
     * @return list<array{table: string, severity: string, kind: string, detail: string}>
     */
    private function compareTable(string $table, array $expected, array $actual): array
    {
        $findings = [];

        foreach (array_diff(array_keys($expected['columns']), array_keys($actual['columns'])) as $column) {
            $findings[] = $this->finding($table, self::DRIFT, 'column.missing', sprintf('`%s` is missing — a fresh build has `%s %s`', $column, $column, $expected['columns'][$column]));
        }
        foreach (array_diff(array_keys($actual['columns']), array_keys($expected['columns'])) as $column) {
            $findings[] = $this->finding($table, self::DRIFT, 'column.extra', sprintf('`%s %s` exists but no migration creates it — a dropped column that was never dropped here', $column, $actual['columns'][$column]));
        }
        foreach (array_intersect(array_keys($expected['columns']), array_keys($actual['columns'])) as $column) {
            if ($expected['columns'][$column] !== $actual['columns'][$column]) {
                $findings[] = $this->finding($table, self::DRIFT, 'column.definition', sprintf('`%s` is `%s`, a fresh build has `%s`', $column, $actual['columns'][$column], $expected['columns'][$column]));
            }
        }

        array_push($findings, ...$this->compareIndexes($table, $expected['indexes'], $actual['indexes']));
        array_push($findings, ...$this->compareForeignKeys($table, $expected['foreign_keys'], $actual['foreign_keys']));

        return $findings;
    }

    /**
     * @return list<array{table: string, severity: string, kind: string, detail: string}>
     */
    private function compareIndexes(string $table, array $expected, array $actual): array
    {
        $findings = [];

        $byShape = static function (array $indexes): array {
            $out = [];
            foreach ($indexes as $name => $index) {
                $out[($index['unique'] ? 'UNIQUE' : 'INDEX').' ('.implode(', ', $index['columns']).')'][] = $name;
            }

            return $out;
        };

        $expectedShapes = $byShape($expected);
        $actualShapes = $byShape($actual);

        foreach (array_diff(array_keys($expectedShapes), array_keys($actualShapes)) as $shape) {
            $findings[] = $this->finding($table, self::DRIFT, 'index.missing', sprintf('%s is missing — a fresh build has it as `%s`', $shape, implode('`, `', $expectedShapes[$shape])));
        }
        foreach (array_diff(array_keys($actualShapes), array_keys($expectedShapes)) as $shape) {
            $findings[] = $this->finding($table, self::DRIFT, 'index.extra', sprintf('%s exists as `%s` but no migration creates it', $shape, implode('`, `', $actualShapes[$shape])));
        }
        foreach (array_intersect(array_keys($expectedShapes), array_keys($actualShapes)) as $shape) {
            sort($expectedShapes[$shape]);
            sort($actualShapes[$shape]);
            if ($expectedShapes[$shape] !== $actualShapes[$shape]) {
                $findings[] = $this->finding($table, self::COSMETIC, 'index.name', sprintf('%s is named `%s`, a fresh build names it `%s`', $shape, implode('`, `', $actualShapes[$shape]), implode('`, `', $expectedShapes[$shape])));
            }
        }

        return $findings;
    }

    /**
     * @return list<array{table: string, severity: string, kind: string, detail: string}>
     */
    private function compareForeignKeys(string $table, array $expected, array $actual): array
    {
        $findings = [];

        $byShape = static function (array $constraints): array {
            $out = [];
            foreach ($constraints as $name => $fk) {
                $shape = sprintf(
                    '(%s) REFERENCES %s (%s)',
                    implode(', ', $fk['columns']),
                    $fk['referenced_table'],
                    implode(', ', $fk['referenced_columns']),
                );
                $out[$shape][$name] = strtoupper($fk['on_delete']).' / '.strtoupper($fk['on_update']);
            }

            return $out;
        };

        $expectedShapes = $byShape($expected);
        $actualShapes = $byShape($actual);

        foreach (array_diff(array_keys($expectedShapes), array_keys($actualShapes)) as $shape) {
            $findings[] = $this->finding($table, self::DRIFT, 'foreign_key.missing', sprintf('FOREIGN KEY %s is missing — nothing stops an orphan row here', $shape));
        }
        foreach (array_diff(array_keys($actualShapes), array_keys($expectedShapes)) as $shape) {
            $findings[] = $this->finding($table, self::DRIFT, 'foreign_key.extra', sprintf('FOREIGN KEY %s exists but no migration creates it', $shape));
        }
        foreach (array_intersect(array_keys($expectedShapes), array_keys($actualShapes)) as $shape) {
            $expectedRules = array_values(array_unique(array_values($expectedShapes[$shape])));
            $actualRules = array_values(array_unique(array_values($actualShapes[$shape])));
            sort($expectedRules);
            sort($actualRules);

            if ($expectedRules !== $actualRules) {
                $findings[] = $this->finding($table, self::DRIFT, 'foreign_key.rules', sprintf('FOREIGN KEY %s is ON DELETE/UPDATE %s, a fresh build has %s', $shape, implode(', ', $actualRules), implode(', ', $expectedRules)));

                continue;
            }

            $expectedNames = array_keys($expectedShapes[$shape]);
            $actualNames = array_keys($actualShapes[$shape]);
            sort($expectedNames);
            sort($actualNames);
            if ($expectedNames !== $actualNames) {
                $findings[] = $this->finding($table, self::COSMETIC, 'foreign_key.name', sprintf('FOREIGN KEY %s is named `%s`, a fresh build names it `%s`', $shape, implode('`, `', $actualNames), implode('`, `', $expectedNames)));
            }
        }

        return $findings;
    }

    /**
     * @return array{table: string, severity: string, kind: string, detail: string}
     */
    private function finding(string $table, string $severity, string $kind, string $detail): array
    {
        return ['table' => $table, 'severity' => $severity, 'kind' => $kind, 'detail' => $detail];
    }
}
