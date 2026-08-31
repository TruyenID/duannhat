<?php

use App\Support\Schema\SchemaDiffer;

/**
 * #1216 — the comparator behind `php artisan schema:drift-check`.
 *
 * Kept pure and unit-tested on purpose: the command it serves can only run
 * against a real MySQL server that has been migrating for months, which is
 * exactly the thing CI does not have. Everything that decides WHAT COUNTS as
 * drift therefore lives here, where it can be tested without one.
 *
 * The cases are worded from the real report so they stay recognisable:
 * `role_permissions` missing its `id` column while the model declared
 * `$primaryKey = 'id'`, `customer_orders` missing the foreign key on
 * `table_session_id`, and the `_foreign` vs `_index` naming split that must not
 * be mistaken for a missing index.
 */
function driftTable(array $columns = [], array $indexes = [], array $foreignKeys = []): array
{
    return ['columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreignKeys];
}

function driftIndex(array $columns, bool $unique = false): array
{
    return ['unique' => $unique, 'columns' => $columns];
}

function driftForeignKey(array $columns, string $refTable, array $refColumns, string $onDelete = 'RESTRICT', string $onUpdate = 'RESTRICT'): array
{
    return [
        'columns' => $columns,
        'referenced_table' => $refTable,
        'referenced_columns' => $refColumns,
        'on_delete' => $onDelete,
        'on_update' => $onUpdate,
    ];
}

it('reports nothing when the two schemas agree', function () {
    $schema = [
        'orders' => driftTable(
            columns: ['id' => 'bigint unsigned NOT NULL auto_increment', 'total' => 'int NOT NULL'],
            indexes: ['PRIMARY' => driftIndex(['id'], unique: true)],
        ),
    ];

    expect((new SchemaDiffer)->compare($schema, $schema))->toBe([]);
});

it('flags a column the live database does not have', function () {
    $findings = (new SchemaDiffer)->compare(
        ['role_permissions' => driftTable(columns: ['id' => 'bigint unsigned NOT NULL', 'role_id' => 'char(26) NOT NULL'])],
        ['role_permissions' => driftTable(columns: ['role_id' => 'char(26) NOT NULL'])],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe('column.missing')
        ->and($findings[0]['severity'])->toBe(SchemaDiffer::DRIFT)
        ->and($findings[0]['detail'])->toContain('`id` is missing');
});

it('flags a column the migrations no longer create', function () {
    $findings = (new SchemaDiffer)->compare(
        ['users' => driftTable(columns: ['id' => 'bigint NOT NULL'])],
        ['users' => driftTable(columns: ['id' => 'bigint NOT NULL', 'is_standalone' => 'tinyint(1) NOT NULL DEFAULT 0'])],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe('column.extra')
        ->and($findings[0]['severity'])->toBe(SchemaDiffer::DRIFT);
});

it('flags a narrower column on the live database', function () {
    $findings = (new SchemaDiffer)->compare(
        ['payment_methods' => driftTable(columns: ['type' => 'varchar(50) NOT NULL'])],
        ['payment_methods' => driftTable(columns: ['type' => 'varchar(20) NOT NULL'])],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe('column.definition')
        ->and($findings[0]['detail'])->toContain('varchar(20)')
        ->and($findings[0]['detail'])->toContain('varchar(50)');
});

it('flags an index the live database is missing', function () {
    $findings = (new SchemaDiffer)->compare(
        ['customer_orders' => driftTable(indexes: ['customer_orders_table_session_id_index' => driftIndex(['table_session_id'])])],
        ['customer_orders' => driftTable()],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe('index.missing')
        ->and($findings[0]['severity'])->toBe(SchemaDiffer::DRIFT);
});

it('treats the same index under a different name as cosmetic, not missing', function () {
    $findings = (new SchemaDiffer)->compare(
        ['menu_sections' => driftTable(indexes: ['menu_sections_brand_id_index' => driftIndex(['brand_id'])])],
        ['menu_sections' => driftTable(indexes: ['menu_sections_brand_id_foreign' => driftIndex(['brand_id'])])],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe('index.name')
        ->and($findings[0]['severity'])->toBe(SchemaDiffer::COSMETIC);
});

it('does not confuse a unique index with a plain one on the same column', function () {
    $findings = (new SchemaDiffer)->compare(
        ['tills' => driftTable(indexes: ['tills_code_unique' => driftIndex(['code'], unique: true)])],
        ['tills' => driftTable(indexes: ['tills_code_index' => driftIndex(['code'])])],
    );

    expect(array_column($findings, 'kind'))->toBe(['index.extra', 'index.missing'])
        ->and(array_column($findings, 'severity'))->toBe([SchemaDiffer::DRIFT, SchemaDiffer::DRIFT]);
});

it('flags a foreign key the live database is missing', function () {
    $findings = (new SchemaDiffer)->compare(
        ['customer_orders' => driftTable(foreignKeys: [
            'customer_orders_table_session_id_foreign' => driftForeignKey(['table_session_id'], 'table_sessions', ['id'], 'SET NULL', 'CASCADE'),
        ])],
        ['customer_orders' => driftTable()],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe('foreign_key.missing')
        ->and($findings[0]['severity'])->toBe(SchemaDiffer::DRIFT)
        ->and($findings[0]['detail'])->toContain('table_sessions');
});

it('flags a foreign key whose referential action differs', function () {
    $findings = (new SchemaDiffer)->compare(
        ['orders' => driftTable(foreignKeys: ['fk' => driftForeignKey(['branch_id'], 'branches', ['id'], 'CASCADE', 'CASCADE')])],
        ['orders' => driftTable(foreignKeys: ['fk' => driftForeignKey(['branch_id'], 'branches', ['id'], 'CASCADE', 'NO ACTION')])],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe('foreign_key.rules')
        ->and($findings[0]['severity'])->toBe(SchemaDiffer::DRIFT);
});

it('treats the same foreign key under a different name as cosmetic', function () {
    $findings = (new SchemaDiffer)->compare(
        ['orders' => driftTable(foreignKeys: ['orders_branch_id_foreign' => driftForeignKey(['branch_id'], 'branches', ['id'], 'CASCADE', 'CASCADE')])],
        ['orders' => driftTable(foreignKeys: ['fk_orders_branch' => driftForeignKey(['branch_id'], 'branches', ['id'], 'CASCADE', 'CASCADE')])],
    );

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe('foreign_key.name')
        ->and($findings[0]['severity'])->toBe(SchemaDiffer::COSMETIC);
});

it('flags tables that exist on only one side', function () {
    $findings = (new SchemaDiffer)->compare(
        ['brand_new' => driftTable()],
        ['long_forgotten' => driftTable()],
    );

    expect(array_column($findings, 'kind'))->toBe(['table.missing', 'table.extra']);
});

it('lets a caller filter accepted differences out of the report', function () {
    $findings = (new SchemaDiffer)->compare(
        ['users' => driftTable(columns: ['id' => 'bigint NOT NULL'])],
        ['users' => driftTable(columns: ['id' => 'bigint NOT NULL', 'is_standalone' => 'tinyint(1) NOT NULL DEFAULT 0'])],
        ignore: fn (array $finding): bool => $finding['table'] === 'users' && str_contains($finding['detail'], 'is_standalone'),
    );

    expect($findings)->toBe([]);
});

it('sorts findings so two runs of the same comparison read identically', function () {
    $findings = (new SchemaDiffer)->compare(
        [
            'zebra' => driftTable(columns: ['a' => 'int NOT NULL']),
            'apple' => driftTable(columns: ['a' => 'int NOT NULL']),
        ],
        [
            'zebra' => driftTable(columns: ['a' => 'bigint NOT NULL']),
            'apple' => driftTable(columns: ['a' => 'bigint NOT NULL']),
        ],
    );

    expect(array_column($findings, 'table'))->toBe(['apple', 'zebra']);
});
