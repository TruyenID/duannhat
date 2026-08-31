<?php

/**
 * Plan-023 M8 T8.15 arch test 1 — Approval-workflow coverage.
 *
 * Every model that participates in an approval workflow (Product, Menu)
 * must have at least one system rule covering each of the three lifecycle
 * stages: submitted / approved / rejected.
 *
 * These tests read the seeder's static rule list directly — no DB
 * required — so they work in the Arch test context (no RefreshDatabase).
 */

use Database\Seeders\SystemNotificationRuleSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;

it('M8-arch-1a: Product has rules for submitted, approved, and rejected lifecycle events', function () {
    $rules = collect(SystemNotificationRuleSeeder::systemRules())
        ->where('trigger_model_type', 'Product');

    $names = $rules->pluck('name')->map(fn ($n) => strtolower($n));

    $hasSubmitted = $names->contains(fn ($n) => str_contains($n, 'submitted'));
    $hasApproved = $names->contains(fn ($n) => str_contains($n, 'approved'));
    $hasRejected = $names->contains(fn ($n) => str_contains($n, 'rejected'));

    expect($hasSubmitted)->toBeTrue('Product is missing a "submitted for approval" rule')
        ->and($hasApproved)->toBeTrue('Product is missing an "approved" rule')
        ->and($hasRejected)->toBeTrue('Product is missing a "rejected" rule');
});

it('M8-arch-1b: Menu has rules for submitted, approved, and rejected lifecycle events', function () {
    $rules = collect(SystemNotificationRuleSeeder::systemRules())
        ->where('trigger_model_type', 'Menu');

    $names = $rules->pluck('name')->map(fn ($n) => strtolower($n));

    $hasSubmitted = $names->contains(fn ($n) => str_contains($n, 'submitted'));
    $hasApproved = $names->contains(fn ($n) => str_contains($n, 'approved'));
    $hasRejected = $names->contains(fn ($n) => str_contains($n, 'rejected'));

    expect($hasSubmitted)->toBeTrue('Menu is missing a "submitted for approval" rule')
        ->and($hasApproved)->toBeTrue('Menu is missing an "approved" rule')
        ->and($hasRejected)->toBeTrue('Menu is missing a "rejected" rule');
});

it('M8-arch-1c: all M8 system rules reference a morph-map-registered trigger_model_type or are custom events', function () {
    $morphMap = Relation::morphMap();
    $registeredAliases = array_keys($morphMap);

    $offenders = [];
    foreach (SystemNotificationRuleSeeder::systemRules() as $rule) {
        $modelType = $rule['trigger_model_type'] ?? null;
        $event = $rule['trigger_event'] ?? '';

        // custom.* events do not require a morph-map alias
        if ($modelType === null || str_starts_with($event, 'custom.')) {
            continue;
        }

        if (! in_array($modelType, $registeredAliases, true)) {
            $offenders[] = "{$rule['name']} → trigger_model_type '{$modelType}' not in morph map";
        }
    }

    expect($offenders)->toBeEmpty(
        'System rules reference unregistered morph aliases:'.PHP_EOL
        .implode(PHP_EOL, $offenders),
    );
});
