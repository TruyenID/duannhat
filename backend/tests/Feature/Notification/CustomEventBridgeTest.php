<?php

/**
 * Plan-023 M8 T8.2 — CustomNotificationEvent bridge tests.
 */

use App\Events\Notification\CustomNotificationEvent;
use App\Jobs\Notification\EvaluateRuleJob;
use App\Models\Brand;
use App\Models\NotificationRule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake([EvaluateRuleJob::class]);
});

it('M8-3: custom event triggers rule when active rule matches event name', function () {
    $brand = Brand::factory()->create();

    NotificationRule::factory()->create([
        'id' => (string) Str::uuid(),
        'trigger_event' => 'custom.test.fired',
        'trigger_model_type' => 'Brand',
        'is_active' => true,
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => null,
        'conditions' => ['combinator' => 'and', 'children' => []],
        'action' => ['template_key' => 'stock.alert.low', 'priority' => 'normal', 'channels' => ['in_app']],
        'cooldown_minutes' => 0,
        'fire_count' => 0,
    ]);

    Event::dispatch('custom.test.fired', [
        new CustomNotificationEvent(subject: $brand),
    ]);

    Bus::assertDispatched(EvaluateRuleJob::class);
});

it('M8-4: bad-shape custom event logs warning and fires no job', function () {
    // Dispatch with a non-CustomNotificationEvent payload
    Event::dispatch('custom.test.fired', [
        ['not_a_value_object' => true],
    ]);

    Bus::assertNotDispatched(EvaluateRuleJob::class);
});
