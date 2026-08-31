<?php

/**
 * /api/v1/me/notification-preferences tests (plan-012 T3.10).
 */

use App\Jobs\NotificationChannelJob;
use App\Models\NotificationChannelRoute;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

describe('GET /me/notification-preferences', function () {
    it('returns the user prefs matrix + master + quiet hours shape', function () {
        NotificationPreference::query()->create([
            'user_id' => $this->user->id,
            'type' => 'stock.alert.low',
            'channel' => 'email',
            'enabled' => false,
        ]);
        NotificationPreference::query()->create([
            'user_id' => $this->user->id,
            'type' => '*',
            'channel' => '*',
            'enabled' => true,
            'master_mute' => true,
            'quiet_from' => '22:00',
            'quiet_to' => '07:00',
            'quiet_timezone' => 'Asia/Tokyo',
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.master_mute', true)
            ->assertJsonPath('data.quiet_hours.from', '22:00')
            ->assertJsonPath('data.quiet_hours.to', '07:00')
            ->assertJsonPath('data.quiet_hours.tz', 'Asia/Tokyo')
            ->assertJsonPath('data.preferences.0.type', 'stock.alert.low')
            ->assertJsonPath('data.preferences.0.channel', 'email')
            ->assertJsonPath('data.preferences.0.enabled', false);
    });

    it('returns defaults when no rows exist', function () {
        $this->actingAs($this->user)
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.master_mute', false)
            ->assertJsonPath('data.preferences', []);
    });
});

describe('PUT /me/notification-preferences/{type}/{channel}', function () {
    it('upserts an opt-out row', function () {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/notification-preferences/stock.alert.low/email', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $row = NotificationPreference::query()
            ->where('user_id', $this->user->id)
            ->where('type', 'stock.alert.low')
            ->where('channel', 'email')
            ->sole();
        expect($row->enabled)->toBeFalse();
    });

    it('prevents email delivery on the next dispatch once email is disabled', function () {
        Bus::fake([NotificationChannelJob::class]);

        NotificationChannelRoute::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'stock.alert.low',
            'channels' => ['in_app', 'email'],
        ]);

        $this->actingAs($this->user)
            ->putJson('/api/v1/me/notification-preferences/stock.alert.low/email', ['enabled' => false])
            ->assertOk();

        app(NotificationService::class)->dispatch([
            'type' => 'stock.alert.low',
            'organization_id' => $this->orgId,
            'recipients' => [$this->user],
        ]);

        $channels = NotificationDelivery::query()
            ->whereHas('notificationRecipient.notification', fn ($q) => $q->where('type', 'stock.alert.low'))
            ->pluck('channel')
            ->map(fn ($c) => $c->value)
            ->all();

        expect($channels)->toBe(['in_app']);
    });

    it('rejects an unknown channel', function () {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/notification-preferences/stock.alert.low/sms', ['enabled' => true])
            ->assertStatus(404); // route constraint rejects sms
    });
});

describe('PUT /me/notification-preferences/master-mute', function () {
    it('toggles master mute on', function () {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/notification-preferences/master-mute', ['master_mute' => true])
            ->assertOk()
            ->assertJsonPath('data.master_mute', true);

        $master = NotificationPreference::query()
            ->where('user_id', $this->user->id)
            ->where('type', '*')->where('channel', '*')->sole();
        expect($master->master_mute)->toBeTrue();
    });
});

describe('PUT /me/notification-preferences/quiet-hours', function () {
    it('persists from/to/tz on the master row', function () {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/notification-preferences/quiet-hours', [
                'from' => '22:00',
                'to' => '07:00',
                'tz' => 'Asia/Tokyo',
            ])
            ->assertOk()
            ->assertJsonPath('data.from', '22:00')
            ->assertJsonPath('data.tz', 'Asia/Tokyo');
    });

    it('rejects unknown timezones', function () {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/notification-preferences/quiet-hours', [
                'from' => '22:00',
                'to' => '07:00',
                'tz' => 'Mars/Phobos',
            ])
            ->assertStatus(422);
    });
});

describe('GET /me/notifications/types', function () {
    it("returns distinct types present in the user's inbox", function () {
        Bus::fake([NotificationChannelJob::class]);

        $svc = app(NotificationService::class);
        $svc->dispatch([
            'type' => 'stock.alert.low',
            'organization_id' => $this->orgId,
            'recipients' => [$this->user],
        ]);
        $svc->dispatch([
            'type' => 'recipe.approved',
            'organization_id' => $this->orgId,
            'recipients' => [$this->user],
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/me/notifications/types')
            ->assertOk()
            ->assertJsonPath('data', ['recipe.approved', 'stock.alert.low']);
    });
});
