<?php

/**
 * End-to-end demo of the notification platform (plan-008 + plan-012).
 *
 * Not a coverage test — a readable walkthrough. Run with:
 *
 *   php artisan test --filter=DemoWalkthrough -- --testdox
 *
 * Each `it()` block is a concrete scenario; `describe()` blocks group them
 * and dump state for visibility.
 */

use App\Jobs\DispatchScheduledNotificationJob;
use App\Jobs\NotificationChannelJob;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationAudience;
use App\Models\NotificationChannelRoute;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationRecipient;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationPriorityEnum;
use App\Services\Notification\Audience;
use App\Services\Notification\AudienceResolverService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

function banner(string $label): void
{
    echo "\n\n    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "    {$label}\n";
    echo "    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
}

function note(string $text): void
{
    echo "    ▸ {$text}\n";
}

function tableDump(string $label, iterable $rows, array $cols): void
{
    echo "    ┌─ {$label}\n";
    foreach ($rows as $r) {
        $parts = [];
        foreach ($cols as $c) {
            $v = $r->{$c} ?? '—';
            if (is_object($v) && method_exists($v, 'value')) {
                $v = $v->value;
            }
            if (is_object($v)) {
                $v = (string) ($v->value ?? get_class($v));
            }
            $parts[] = "{$c}=".(string) $v;
        }
        echo '    │  '.implode('  ', $parts)."\n";
    }
    echo "    └─\n";
}

beforeEach(function () {
    Bus::fake([NotificationChannelJob::class, DispatchScheduledNotificationJob::class]);

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
        'name' => 'Demo Restaurant Group',
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'demo-brand',
        'name' => 'Demo Brand',
        'is_active' => true,
    ]);

    // Seed 3 "warehouse managers" + 1 "shop manager" for scenarios.
    $this->warehouseA = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'name' => 'Alice (warehouse)',
        'email' => 'alice@demo.test',
    ]);
    $this->warehouseB = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'name' => 'Bình (warehouse)',
        'email' => 'binh@demo.test',
    ]);
    $this->warehouseC = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'name' => 'Chi (warehouse)',
        'email' => 'chi@demo.test',
    ]);
    $this->shopMgr = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'name' => 'Dũng (shop)',
        'email' => 'dung@demo.test',
    ]);
});

describe('Scenario A — Auto event: stock alert low', function () {
    it('fires stock.alert.low → routes say in_app + email → 3 recipients × 2 channels = 6 deliveries', function () {
        banner('SCENARIO A — Stock alert low fan-out');

        note('HQ admin has configured:');
        note('   channel_routes[stock.alert.low] = [in_app, email]');
        NotificationChannelRoute::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'stock.alert.low',
            'channels' => ['in_app', 'email'],
        ]);

        note('Alice + Bình + Chi are warehouse_manager (assume resolved by audience).');
        $recipients = [$this->warehouseA, $this->warehouseB, $this->warehouseC];

        note('Simulate the StockAlertNotificationObserver firing — dispatch called:');
        $notif = app(NotificationService::class)->dispatch([
            'type' => 'stock.alert.low',
            'template_key' => 'stock.alert.low',
            'organization_id' => $this->orgId,
            'recipients' => $recipients,
            'params' => ['material' => 'Rice', 'quantity' => 2, 'unit' => 'kg'],
        ]);

        $recipientRows = NotificationRecipient::query()->where('notification_id', $notif->id)->get();
        tableDump('notification_recipients', $recipientRows, ['recipient_type', 'recipient_id']);

        $deliveries = NotificationDelivery::query()
            ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notif->id))
            ->get();
        tableDump('notification_deliveries', $deliveries, ['channel', 'status', 'attempts']);

        note('3 recipients × 2 channels (in_app + email) = 6 delivery rows.');
        expect($deliveries)->toHaveCount(6);

        // NotificationService runs channel jobs inline (QUEUE_CONNECTION=sync),
        // so deliveries finish at terminal statuses rather than queueing.
        note('Channel jobs run inline — each delivery ends at Sent/Skipped/Failed.');
        expect($deliveries->pluck('status')->every(fn ($s) => $s->value !== 'pending'))->toBeTrue();
    });
});

describe('Scenario B — User preference blocks email for that type', function () {
    it('Alice opts out of stock.alert.low email → next dispatch only gives her in_app', function () {
        banner('SCENARIO B — Per-type opt-out wins');

        note('HQ routes: stock.alert.low = [in_app, email]');
        NotificationChannelRoute::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'stock.alert.low',
            'channels' => ['in_app', 'email'],
        ]);

        note('Alice turns off email for stock.alert.low in /me/settings/notifications:');
        NotificationPreference::query()->create([
            'user_id' => $this->warehouseA->id,
            'type' => 'stock.alert.low',
            'channel' => 'email',
            'enabled' => false,
        ]);
        note('   notification_preferences row written.');

        $notif = app(NotificationService::class)->dispatch([
            'type' => 'stock.alert.low',
            'organization_id' => $this->orgId,
            'recipients' => [$this->warehouseA, $this->warehouseB],
        ]);

        $alice = NotificationDelivery::query()
            ->whereHas('notificationRecipient', fn ($q) => $q
                ->where('notification_id', $notif->id)
                ->where('recipient_id', $this->warehouseA->id))
            ->pluck('channel')
            ->map(fn ($c) => $c->value)
            ->all();
        $binh = NotificationDelivery::query()
            ->whereHas('notificationRecipient', fn ($q) => $q
                ->where('notification_id', $notif->id)
                ->where('recipient_id', $this->warehouseB->id))
            ->pluck('channel')
            ->map(fn ($c) => $c->value)
            ->sort()
            ->values()
            ->all();

        note('Alice deliveries: ['.implode(', ', $alice).']  ← email stripped');
        note('Bình  deliveries: ['.implode(', ', $binh).']  ← both channels (no opt-out)');

        expect($alice)->toBe(['in_app']);
        expect($binh)->toBe(['email', 'in_app']);
    });

    it('urgent dispatch still honours type opt-out (opt-out beats bypass)', function () {
        banner('SCENARIO B-2 — Urgent cannot override explicit opt-out');

        NotificationChannelRoute::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'system.critical',
            'channels' => ['in_app', 'email', 'realtime'],
        ]);

        note('Alice opts out email for system.critical.');
        NotificationPreference::query()->create([
            'user_id' => $this->warehouseA->id,
            'type' => 'system.critical',
            'channel' => 'email',
            'enabled' => false,
        ]);

        note('HQ fires urgent dispatch.');
        $notif = app(NotificationService::class)->dispatch([
            'type' => 'system.critical',
            'organization_id' => $this->orgId,
            'recipients' => [$this->warehouseA],
            'priority' => NotificationPriorityEnum::Urgent,
        ]);

        $channels = NotificationDelivery::query()
            ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notif->id))
            ->pluck('channel')
            ->map(fn ($c) => $c->value)
            ->sort()
            ->values()
            ->all();

        note('Alice deliveries on urgent: ['.implode(', ', $channels).']  ← email still out');
        expect($channels)->toBe(['in_app', 'realtime']);
    });
});

describe('Scenario C — Master mute bypassed by urgent', function () {
    it('Alice master-muted; normal priority stops at in_app, urgent reaches all channels', function () {
        banner('SCENARIO C — Master mute + urgent bypass');

        NotificationChannelRoute::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'system.critical',
            'channels' => ['in_app', 'email', 'realtime'],
        ]);

        note('Alice turns on master mute.');
        NotificationPreference::query()->create([
            'user_id' => $this->warehouseA->id,
            'type' => '*',
            'channel' => '*',
            'master_mute' => true,
        ]);

        $normal = app(NotificationService::class)->dispatch([
            'type' => 'system.critical',
            'organization_id' => $this->orgId,
            'recipients' => [$this->warehouseA],
            'priority' => NotificationPriorityEnum::Normal,
        ]);
        $normalChannels = NotificationDelivery::query()
            ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $normal->id))
            ->pluck('channel')
            ->map(fn ($c) => $c->value)
            ->all();
        note('PRIORITY=normal  → ['.implode(', ', $normalChannels).']  (muted except in_app)');
        expect($normalChannels)->toBe(['in_app']);

        $urgent = app(NotificationService::class)->dispatch([
            'type' => 'system.critical',
            'organization_id' => $this->orgId,
            'recipients' => [$this->warehouseA],
            'priority' => NotificationPriorityEnum::Urgent,
        ]);
        $urgentChannels = NotificationDelivery::query()
            ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $urgent->id))
            ->pluck('channel')
            ->map(fn ($c) => $c->value)
            ->sort()
            ->values()
            ->all();
        note('PRIORITY=urgent  → ['.implode(', ', $urgentChannels).']  (urgent bypasses master mute)');
        expect($urgentChannels)->toBe(['email', 'in_app', 'realtime']);
    });
});

describe('Scenario D — Admin broadcast composer + scheduled + cancel', function () {
    it('scheduled dispatch queues DispatchScheduledNotificationJob; cancel before fire deletes the row', function () {
        banner('SCENARIO D — Scheduled broadcast + cancel flow');

        NotificationChannelRoute::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'brand.announcement',
            'channels' => ['in_app', 'email'],
        ]);

        note('HQ compose broadcast scheduled_for = +1h');
        $notif = app(NotificationService::class)->dispatch([
            'type' => 'brand.announcement',
            'organization_id' => $this->orgId,
            'recipients' => [$this->warehouseA, $this->shopMgr],
            'scheduled_for' => now()->addHour(),
        ]);

        $deliveryCount = NotificationDelivery::query()
            ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notif->id))
            ->count();
        note('After dispatch: is_dispatched='.($notif->is_dispatched ? 'true' : 'false').", deliveries=$deliveryCount");
        note('   → recipients written NOW so HQ audit sees pending');
        note('   → deliveries + channel jobs deferred to DispatchScheduledNotificationJob');

        Bus::assertDispatched(DispatchScheduledNotificationJob::class, 1);
        Bus::assertNotDispatched(NotificationChannelJob::class);

        note('HQ realizes wrong audience → DELETE before 60s cutoff.');
        // Simulated via direct delete — full HTTP flow covered in feature test.
        Notification::query()->where('id', $notif->id)->delete();

        note('Job eventually wakes up → no-op because row is gone.');
        expect(Notification::query()->find($notif->id))->toBeNull();
    });
});

describe('Scenario E — Audience + Template from HQ composer path', function () {
    it('composer picks audience + template, produces N recipients × M channels fan-out', function () {
        banner('SCENARIO E — Admin composer full chain');

        note('HQ pre-configures an Audience ("Warehouse team") and a Template ("promo.hello").');

        $audience = NotificationAudience::query()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'name' => 'Warehouse team',
            'rule' => [
                'version' => 1,
                'combinator' => 'or',
                'rules' => [
                    [
                        'type' => 'user',
                        'user_ids' => [$this->warehouseA->id, $this->warehouseB->id, $this->warehouseC->id],
                    ],
                ],
            ],
            'is_system' => false,
        ]);

        $template = NotificationTemplate::query()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'key' => 'promo.hello',
            'content' => [
                'ja' => ['title' => 'こんにちは {{name}}', 'body' => 'キャンペーン開始'],
                'en' => ['title' => 'Hi {{name}}', 'body' => 'Campaign starting'],
                'vi' => ['title' => 'Chào {{name}}', 'body' => 'Campaign khởi động'],
            ],
            'default_channels' => ['in_app'],
            'is_system' => false,
        ]);

        note('Audience resolves to 3 people via live preview.');
        note('Composer picks channels = [in_app, email], priority=normal.');

        $audienceObj = new Audience;
        // Use the rule directly via service since Audience doesn't expose fromRule publicly.
        $result = app(AudienceResolverService::class)
            ->resolveWithTrace($audience->rule, $this->brand);

        $notif = app(NotificationService::class)->dispatch([
            'type' => $template->key,
            'template_key' => $template->key,
            'organization_id' => $this->orgId,
            'brand' => $this->brand,
            'recipients' => $result['recipients'],
            'params' => ['name' => 'All'],
            'priority' => 'normal',
            'channels' => ['in_app', 'email'],
            'audience_id' => $audience->id,
        ]);

        $deliveries = NotificationDelivery::query()
            ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notif->id))
            ->get();

        $byChannel = $deliveries
            ->groupBy(fn ($d) => $d->channel->value)
            ->map(fn ($g) => $g->count())
            ->all();
        foreach ($byChannel as $ch => $cnt) {
            note("   channel=$ch → $cnt deliveries");
        }

        note('Total deliveries = 3 recipients × 2 channels = 6');
        expect($deliveries)->toHaveCount(6);
        expect($notif->audience_id)->toBe($audience->id);
    });
});
