<?php

/**
 * plan-051 T1.2 — pins for THE canonical voidable-status resolution
 * (App\Services\Shop\VoidableStatusResolver). One function, one semantics,
 * shared by the Cloud void gate, the settings serializers and the
 * workstation payload:
 *
 *   item_voidable_statuses non-null → union with ['pending'] (pending hard)
 *   null → legacy allow_item_edit_any_status flag (true → all four active
 *   statuses, false → pending-only)
 *   unknown/garbage entries filtered; `voided` never voidable.
 */

use App\Models\ShopOrderSetting;
use App\Services\Shop\VoidableStatusResolver;

it('resolves pending-only when the shop has no settings row at all', function () {
    expect(VoidableStatusResolver::resolve(null))->toBe(['pending']);
});

it('falls back to the legacy flag when the matrix column is null: false → pending-only', function () {
    $setting = new ShopOrderSetting([
        'item_voidable_statuses' => null,
        'allow_item_edit_any_status' => false,
    ]);

    expect(VoidableStatusResolver::resolve($setting))->toBe(['pending']);
});

it('falls back to the legacy flag when the matrix column is null: true → all four active statuses', function () {
    $setting = new ShopOrderSetting([
        'item_voidable_statuses' => null,
        'allow_item_edit_any_status' => true,
    ]);

    expect(VoidableStatusResolver::resolve($setting))
        ->toBe(['pending', 'preparing', 'ready', 'served']);
});

it('unions pending into an explicit list that omits it — pending is a hard guarantee', function () {
    $setting = new ShopOrderSetting([
        'item_voidable_statuses' => ['preparing', 'ready'],
        'allow_item_edit_any_status' => false,
    ]);

    expect(VoidableStatusResolver::resolve($setting))
        ->toBe(['pending', 'preparing', 'ready']);
});

it('lets an explicit matrix WIN over the legacy flag — an empty list means pending-only even with the flag ON', function () {
    $setting = new ShopOrderSetting([
        'item_voidable_statuses' => [],
        'allow_item_edit_any_status' => true,
    ]);

    expect(VoidableStatusResolver::resolve($setting))->toBe(['pending']);
});

it('filters garbage entries against the real status set (voided and unknowns dropped)', function () {
    $setting = new ShopOrderSetting([
        'item_voidable_statuses' => ['preparing', 'banana', 'voided', 123, ['nested'], 'served'],
        'allow_item_edit_any_status' => false,
    ]);

    expect(VoidableStatusResolver::resolve($setting))
        ->toBe(['pending', 'preparing', 'served']);
});

it('normalizes output to canonical enum order regardless of stored order', function () {
    $setting = new ShopOrderSetting([
        'item_voidable_statuses' => ['served', 'preparing', 'pending', 'ready'],
    ]);

    expect(VoidableStatusResolver::resolve($setting))
        ->toBe(['pending', 'preparing', 'ready', 'served']);
});
