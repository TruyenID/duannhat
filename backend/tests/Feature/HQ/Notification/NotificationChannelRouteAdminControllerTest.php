<?php

/**
 * HQ channel-route admin API tests (plan-012 T3.9).
 */

use App\Jobs\NotificationChannelJob;
use App\Models\Brand;
use App\Models\NotificationChannelRoute;
use App\Models\NotificationDelivery;
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
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'route-hq-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications/channel-routes";
});

describe('index', function () {
    it('returns only routes scoped to the brand org', function () {
        NotificationChannelRoute::query()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'type' => 'stock.alert.low',
            'channels' => ['in_app', 'email'],
        ]);

        // Unrelated org — should not leak.
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
        NotificationChannelRoute::query()->create([
            'organization_id' => $otherOrgId,
            'type' => 'recipe.approved',
            'channels' => ['in_app'],
        ]);

        $types = $this->actingAs($this->user)
            ->getJson($this->base)
            ->assertOk()
            ->json('data.*.type');

        expect($types)->toBe(['stock.alert.low']);
    });

    it('returns 403 for users without brand access', function () {
        $outsider = User::factory()->create([]);

        $this->actingAs($outsider)->getJson($this->base)->assertForbidden();
    });
});

describe('upsert', function () {
    it('creates a new route when none exists for the type', function () {
        $this->actingAs($this->user)->putJson($this->base, [
            'routes' => [
                ['type' => 'stock.alert.low', 'channels' => ['in_app', 'email']],
            ],
        ])->assertOk()
            ->assertJsonPath('data.0.type', 'stock.alert.low')
            ->assertJsonPath('data.0.channels', ['in_app', 'email']);

        expect(NotificationChannelRoute::query()->where('type', 'stock.alert.low')->count())->toBe(1);
    });

    it('updates an existing route in place', function () {
        NotificationChannelRoute::query()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'type' => 'stock.alert.low',
            'channels' => ['in_app', 'email'],
        ]);

        $this->actingAs($this->user)->putJson($this->base, [
            'routes' => [
                ['type' => 'stock.alert.low', 'channels' => ['in_app']],
            ],
        ])->assertOk();

        $row = NotificationChannelRoute::query()->where('type', 'stock.alert.low')->sole();
        expect($row->channels)->toBe(['in_app']);
    });

    it('accepts priority_overrides payload', function () {
        $this->actingAs($this->user)->putJson($this->base, [
            'routes' => [[
                'type' => 'recipe.approved',
                'channels' => ['in_app'],
                'priority_overrides' => ['urgent' => ['in_app', 'realtime', 'email']],
            ]],
        ])->assertOk();

        $row = NotificationChannelRoute::query()->where('type', 'recipe.approved')->sole();
        expect($row->priority_overrides)->toBe(['urgent' => ['in_app', 'realtime', 'email']]);
    });

    it('rejects invalid channel values', function () {
        $this->actingAs($this->user)->putJson($this->base, [
            'routes' => [
                ['type' => 'x.y', 'channels' => ['sms']],
            ],
        ])->assertStatus(422);
    });

    it('rejects invalid priority keys', function () {
        $this->actingAs($this->user)->putJson($this->base, [
            'routes' => [[
                'type' => 'x.y',
                'channels' => ['in_app'],
                'priority_overrides' => ['screaming' => ['in_app']],
            ]],
        ])->assertStatus(422);
    });

    it('disabling email for stock.alert.low prevents email delivery on next dispatch', function () {
        Bus::fake([NotificationChannelJob::class]);

        NotificationChannelRoute::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'stock.alert.low',
            'channels' => ['in_app', 'email'],
        ]);

        // Admin disables email.
        $this->actingAs($this->user)->putJson($this->base, [
            'routes' => [
                ['type' => 'stock.alert.low', 'channels' => ['in_app']],
            ],
        ])->assertOk();

        // Dispatch to a user in the same org.
        $recipient = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        app(NotificationService::class)->dispatch([
            'type' => 'stock.alert.low',
            'organization_id' => $this->orgId,
            'recipients' => [$recipient],
        ]);

        $channels = NotificationDelivery::query()
            ->whereHas('notificationRecipient.notification', fn ($q) => $q->where('type', 'stock.alert.low'))
            ->pluck('channel')
            ->map(fn ($c) => $c->value)
            ->all();

        expect($channels)->toBe(['in_app']);
    });
});

describe('brand_id mass-assignment guard (issue #171)', function () {
    it('ignores caller-supplied brand_id in upsert rows and pins to route brand', function () {
        $foreignBrand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)->putJson($this->base, [
            'routes' => [
                [
                    'type' => 'stock.alert.low',
                    'channels' => ['in_app'],
                    'brand_id' => $foreignBrand->id, // attempt to pin elsewhere
                ],
            ],
        ])->assertOk();

        $row = NotificationChannelRoute::query()->where('type', 'stock.alert.low')->sole();
        expect($row->brand_id)->toBe($this->brand->id)
            ->and($row->brand_id)->not->toBe($foreignBrand->id);
    });
});
