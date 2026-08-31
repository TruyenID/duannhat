<?php

/**
 * Plan-023 M8 T8.14 — Seeder idempotency + historical-non-fire tests.
 *
 * M8-37: running the seeder multiple times must not create duplicate rules.
 * M8-38: seeding against a DB that already has products/menus/etc. in non-fresh
 *         states must not fire any notification jobs (touch() is never called on
 *         historical rows — so no Eloquent events propagate through the bridge).
 */

use App\Models\NotificationRule;
use App\Models\Product;
use Database\Seeders\SystemNotificationRuleSeeder;
use Database\Seeders\SystemNotificationTemplateSeeder;
use Illuminate\Support\Facades\Queue;

it('M8-37: seeder is idempotent — running it three times yields the same rule count', function () {
    (new SystemNotificationTemplateSeeder)->run();

    (new SystemNotificationRuleSeeder)->run();
    $countAfterFirst = NotificationRule::count();
    expect($countAfterFirst)->toBeGreaterThan(0);

    (new SystemNotificationRuleSeeder)->run();
    expect(NotificationRule::count())->toBe($countAfterFirst);

    (new SystemNotificationRuleSeeder)->run();
    expect(NotificationRule::count())->toBe($countAfterFirst);
});

it('M8-38: seeder does not fire notification jobs for pre-existing models', function () {
    Queue::fake();

    // Create products with various statuses BEFORE seeding — simulates a production
    // DB with historical rows.
    Product::factory()->count(3)->create(['status' => 'pending']);
    Product::factory()->count(2)->create(['status' => 'approved']);

    (new SystemNotificationTemplateSeeder)->run();
    (new SystemNotificationRuleSeeder)->run();

    // The seeder uses firstOrCreate which does NOT call touch() on existing rows,
    // so no model.created / model.updated events propagate through the bridge.
    Queue::assertNothingPushed();
});
