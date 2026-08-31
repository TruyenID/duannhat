<?php

/**
 * Plan-023 M8 T8.6–T8.12 — System notification rule seeder tests.
 * Verifies all M8 rules and templates are seeded correctly.
 */

use App\Models\NotificationRule;
use App\Models\NotificationTemplate;
use Database\Seeders\SystemNotificationRuleSeeder;
use Database\Seeders\SystemNotificationTemplateSeeder;

beforeEach(function () {
    // Run template seeder first
    (new SystemNotificationTemplateSeeder)->run();
    // Run rule seeder (requires org '00000000-0000-0000-0000-000000000001' from Pest.php beforeEach)
    (new SystemNotificationRuleSeeder)->run();
});

it('M8-16: product.submitted_for_approval rule is seeded', function () {
    expect(
        NotificationRule::where('name', 'System: product submitted for approval')
            ->where('trigger_event', 'model.updated')
            ->where('trigger_model_type', 'Product')
            ->exists()
    )->toBeTrue();
});

it('M8-17: product.approved rule is seeded with model_user audience', function () {
    $rule = NotificationRule::where('name', 'System: product approved')->first();

    expect($rule)->not->toBeNull()
        ->and($rule->action['audience_rule']['type'])->toBe('model_user')
        ->and($rule->action['audience_rule']['field'])->toBe('created_by_id');
});

it('M8-18: product.rejected rule uses high priority + email channel', function () {
    $rule = NotificationRule::where('name', 'System: product rejected')->first();

    expect($rule)->not->toBeNull()
        ->and($rule->action['priority'])->toBe('high')
        ->and($rule->action['channels'])->toContain('email');
});

it('M8-19: menu.submitted_for_approval rule is seeded', function () {
    expect(
        NotificationRule::where('name', 'System: menu submitted for approval')->exists()
    )->toBeTrue();
});

it('M8-20: menu.approved audience is model_user', function () {
    $rule = NotificationRule::where('name', 'System: menu approved')->first();
    expect($rule?->action['audience_rule']['type'])->toBe('model_user');
});

it('M8-21: menu.rejected uses email channel', function () {
    $rule = NotificationRule::where('name', 'System: menu rejected')->first();
    expect($rule?->action['channels'])->toContain('email');
});

it('M8-22: stock_transaction.submitted rule is seeded', function () {
    expect(
        NotificationRule::where('name', 'System: stock transaction submitted')->exists()
    )->toBeTrue();
});

it('M8-23: stock_transaction.approved fires to requester (created_by_id)', function () {
    $rule = NotificationRule::where('name', 'System: stock transaction approved')->first();
    expect($rule?->action['audience_rule']['field'])->toBe('created_by_id');
});

it('M8-24: stock_transaction.rejected is high priority', function () {
    $rule = NotificationRule::where('name', 'System: stock transaction rejected')->first();
    expect($rule?->action['priority'])->toBe('high');
});

it('M8-25: stock_transfer.in_transit notifies destination warehouse branch', function () {
    // StockTransfer has no branch_id column — scope must traverse the warehouse
    // relation to its branch, else the branch-scoped resolver gets a warehouse
    // UUID and resolves to nobody.
    $rule = NotificationRule::where('name', 'System: stock transfer in transit')->first();
    expect($rule?->action['audience_rule']['branch_field'])->toBe('destinationWarehouse.branch_id');
});

it('M8-26: stock_transfer.received notifies source warehouse branch', function () {
    $rule = NotificationRule::where('name', 'System: stock transfer received')->first();
    expect($rule?->action['audience_rule']['branch_field'])->toBe('sourceWarehouse.branch_id');
});

it('M8-27: device.paired rule is seeded', function () {
    expect(
        NotificationRule::where('name', 'System: device paired')->exists()
    )->toBeTrue();
});

it('M8-28: device.unpaired uses email channel', function () {
    $rule = NotificationRule::where('name', 'System: device unpaired')->first();
    expect($rule?->action['channels'])->toContain('email');
});

it('M8-29: device.offline fires from custom event with minutes_offline in params', function () {
    $rule = NotificationRule::where('name', 'System: device offline')->first();
    expect($rule)->not->toBeNull()
        ->and($rule->trigger_event)->toBe('custom.device.offline.detected')
        ->and($rule->action['param_template']['$minutes_offline'])->toBe('$context.minutes_offline');
});

it('M8-30: coupon.redeemed rule is seeded', function () {
    expect(
        NotificationRule::where('name', 'System: coupon redeemed')->exists()
    )->toBeTrue();
});

it('M8-31: coupon.expiring_soon has hours_remaining param', function () {
    $rule = NotificationRule::where('name', 'System: coupon expiring soon')->first();
    expect($rule?->action['param_template']['$hours_remaining'])->toBe('$context.hours_remaining');
});

it('M8-32: coupon.expired is seeded', function () {
    expect(
        NotificationRule::where('name', 'System: coupon expired')->exists()
    )->toBeTrue();
});

it('M8-33: brand.status_changed rule fires on is_active change', function () {
    $rule = NotificationRule::where('name', 'System: brand status changed')->first();
    expect($rule)->not->toBeNull()
        ->and($rule->conditions['field'])->toBe('is_active')
        ->and($rule->conditions['op'])->toBe('changed');
});

it('M8-34: brand.status_changed audience scoped to organization', function () {
    $rule = NotificationRule::where('name', 'System: brand status changed')->first();
    expect($rule?->action['audience_rule']['scope'])->toBe('organization');
});

it('M8 templates: all M8 templates are seeded', function () {
    $expectedKeys = [
        'product.submitted_for_approval',
        'product.approved',
        'product.rejected',
        'menu.submitted_for_approval',
        'menu.approved',
        'menu.rejected',
        'stock_transaction.submitted',
        'stock_transaction.approved',
        'stock_transaction.rejected',
        'stock_transfer.in_transit',
        'stock_transfer.received',
        'device.paired',
        'device.unpaired',
        'device.offline',
        'coupon.redeemed',
        'coupon.expiring_soon',
        'coupon.expired',
        'brand.status_changed',
    ];

    foreach ($expectedKeys as $key) {
        expect(NotificationTemplate::where('key', $key)->exists())
            ->toBeTrue("Template '{$key}' should exist");
    }
});
