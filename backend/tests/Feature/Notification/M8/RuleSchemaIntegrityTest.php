<?php

/**
 * Plan-023 M7 T7.1 (TESTS.md M7-1) — notification_rules + notification_rule_firings
 * schema integrity.
 *
 * Locks the columns, key nullability, composite indexes used by the bridge
 * lookup + cooldown scan, and the FK cascade / set-null delete semantics that
 * the rule engine relies on. A migration that silently drops the
 * (trigger_event, trigger_model_type, is_active) index would degrade every
 * Eloquent write into a full table scan; a firings FK that stops cascading
 * would orphan audit rows on rule delete.
 */

use Illuminate\Support\Facades\Schema;

it('M7-1 notification_rules has every plan-023 column', function () {
    $columns = [
        'id', 'organization_id', 'brand_id', 'branch_id', 'name', 'description',
        'trigger_event', 'trigger_model_type', 'conditions', 'action',
        'cooldown_minutes', 'is_active', 'last_fired_at', 'fire_count',
        'created_by_id', 'created_at', 'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('notification_rules', $column))
            ->toBeTrue("notification_rules is missing column [{$column}]");
    }
});

it('M7-1 notification_rule_firings has every plan-023 column', function () {
    $columns = [
        'id', 'rule_id', 'notification_id', 'model_type', 'model_id',
        'fired_at', 'outcome', 'evaluation_trace', 'error_message',
        'created_at', 'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('notification_rule_firings', $column))
            ->toBeTrue("notification_rule_firings is missing column [{$column}]");
    }
});

it('M7-1 the bridge-lookup composite index exists on notification_rules', function () {
    $indexes = collect(Schema::getIndexes('notification_rules'));

    $hasBridgeIndex = $indexes->contains(function (array $index) {
        return $index['columns'] === ['trigger_event', 'trigger_model_type', 'is_active'];
    });
    $hasBrandActive = $indexes->contains(fn (array $i) => $i['columns'] === ['brand_id', 'is_active']);
    $hasBranchActive = $indexes->contains(fn (array $i) => $i['columns'] === ['branch_id', 'is_active']);

    expect($hasBridgeIndex)->toBeTrue('missing [trigger_event, trigger_model_type, is_active] index')
        ->and($hasBrandActive)->toBeTrue('missing [brand_id, is_active] index')
        ->and($hasBranchActive)->toBeTrue('missing [branch_id, is_active] index');
});

it('M7-1 the cooldown-scan composite indexes exist on notification_rule_firings', function () {
    $indexes = collect(Schema::getIndexes('notification_rule_firings'));

    $hasRuleFired = $indexes->contains(fn (array $i) => $i['columns'] === ['rule_id', 'fired_at']);
    $hasModelFired = $indexes->contains(fn (array $i) => $i['columns'] === ['model_type', 'model_id', 'fired_at']);

    expect($hasRuleFired)->toBeTrue('missing [rule_id, fired_at] index')
        ->and($hasModelFired)->toBeTrue('missing [model_type, model_id, fired_at] index');
});

it('M7-1 firings FK to rules cascades on delete; FK to notifications is set-null', function () {
    // The test DB is SQLite :memory: (no runtime FK enforcement), so assert the
    // declared delete semantics from the schema definition rather than driving a
    // cascade at runtime. Production MySQL enforces these.
    $fks = collect(Schema::getForeignKeys('notification_rule_firings'));

    $ruleFk = $fks->firstWhere('columns', ['rule_id']);
    $notificationFk = $fks->firstWhere('columns', ['notification_id']);

    expect($ruleFk)->not->toBeNull()
        ->and($ruleFk['foreign_table'])->toBe('notification_rules')
        ->and(strtolower((string) $ruleFk['on_delete']))->toBe('cascade');

    expect($notificationFk)->not->toBeNull()
        ->and($notificationFk['foreign_table'])->toBe('notifications')
        ->and(strtolower((string) $notificationFk['on_delete']))->toBe('set null');
});

it('M7-1 rules FK to organizations/brands/branches cascade, created_by set-null', function () {
    $fks = collect(Schema::getForeignKeys('notification_rules'));

    foreach (['organization_id' => 'cascade', 'brand_id' => 'cascade', 'branch_id' => 'cascade', 'created_by_id' => 'set null'] as $col => $onDelete) {
        $fk = $fks->firstWhere('columns', [$col]);
        expect($fk)->not->toBeNull("missing FK on [{$col}]")
            ->and(strtolower((string) $fk['on_delete']))->toBe($onDelete);
    }
});
