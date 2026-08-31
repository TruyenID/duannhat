<?php

/**
 * NotificationService unit tests (plan-008 T9.2) — isolated logic:
 * default priority lookup, recipient dedup, input validation. These are
 * unit tests in the Pest sense (no HTTP); RefreshDatabase still runs
 * because the service writes to the DB in every call.
 */

use App\Exceptions\NotificationException;
use App\Models\Device;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationPriorityEnum;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
        'name' => 'Test Org',
        'slug' => 'test-org-notif-unit',
    ]);

    $this->service = app(NotificationService::class);
});

// =========================================================================
//  defaultPriorityFor
// =========================================================================

describe('defaultPriorityFor', function () {
    it('maps stock.alert.out to high', function () {
        expect($this->service->defaultPriorityFor('stock.alert.out'))
            ->toBe(NotificationPriorityEnum::High);
    });

    it('maps stock.alert.low to normal', function () {
        expect($this->service->defaultPriorityFor('stock.alert.low'))
            ->toBe(NotificationPriorityEnum::Normal);
    });

    it('maps system.critical to urgent', function () {
        expect($this->service->defaultPriorityFor('system.critical'))
            ->toBe(NotificationPriorityEnum::Urgent);
    });

    it('falls back to normal for unknown types', function () {
        expect($this->service->defaultPriorityFor('something.unknown'))
            ->toBe(NotificationPriorityEnum::Normal);
    });

    it('applies the default priority during dispatch when not explicitly set', function () {
        $user = User::factory()->create(['console_organization_id' => $this->orgId]);

        $n = $this->service->dispatch([
            'type' => 'stock.alert.out',
            'recipients' => [$user],
            'organization_id' => $this->orgId,
            'idempotency_key' => 'unit-priority-'.Str::random(4),
        ]);

        expect($n->priority)->toBe(NotificationPriorityEnum::High);
    });
});

// =========================================================================
//  Recipient dedup
// =========================================================================

describe('recipient normalization', function () {
    it('dedupes repeated references to the same model', function () {
        $user = User::factory()->create(['console_organization_id' => $this->orgId]);

        $n = $this->service->dispatch([
            'type' => 'test.dedup',
            'recipients' => [$user, $user, $user, $user],
            'organization_id' => $this->orgId,
            'idempotency_key' => 'unit-dedup-'.Str::random(4),
        ]);

        expect(NotificationRecipient::where('notification_id', $n->id)->count())
            ->toBe(1);
    });

    it('rejects non-Notifiable models even when they are Eloquent', function () {
        // Organization is an Eloquent Model but does NOT implement App\Contracts\Notifiable.
        $org = Organization::find($this->orgId);

        expect(fn () => $this->service->dispatch([
            'type' => 'test',
            'recipients' => [$org],
            'organization_id' => $this->orgId,
        ]))->toThrow(NotificationException::class);
    });

    it('preserves mixed morph types across one dispatch', function () {
        $user = User::factory()->create(['console_organization_id' => $this->orgId]);
        $device = Device::factory()->create([
            'organization_id' => $this->orgId,
            'pairing_code' => 'ARCH01',
        ]);

        $n = $this->service->dispatch([
            'type' => 'test.mixed',
            'recipients' => [$user, $device],
            'organization_id' => $this->orgId,
            'idempotency_key' => 'unit-mixed-'.Str::random(4),
        ]);

        $types = NotificationRecipient::where('notification_id', $n->id)
            ->pluck('recipient_type')
            ->sort()
            ->values()
            ->all();

        expect($types)->toBe(['Device', 'User']);
    });
});

// =========================================================================
//  Prunable query
// =========================================================================

describe('Prunable retention', function () {
    it('selects normal-priority rows older than 30 days', function () {
        $user = User::factory()->create(['console_organization_id' => $this->orgId]);

        $old = $this->service->dispatch([
            'type' => 'test.prune.old',
            'recipients' => [$user],
            'organization_id' => $this->orgId,
            'priority' => NotificationPriorityEnum::Normal,
            'idempotency_key' => 'prune-old-'.Str::random(4),
        ]);
        Notification::where('id', $old->id)->update(['created_at' => now()->subDays(40)]);

        $fresh = $this->service->dispatch([
            'type' => 'test.prune.fresh',
            'recipients' => [$user],
            'organization_id' => $this->orgId,
            'priority' => NotificationPriorityEnum::Normal,
            'idempotency_key' => 'prune-fresh-'.Str::random(4),
        ]);

        $prunableIds = (new Notification)->prunable()->pluck('id')->all();

        expect($prunableIds)->toContain($old->id)
            ->and($prunableIds)->not->toContain($fresh->id);
    });

    it('keeps urgent rows up to 90 days', function () {
        $user = User::factory()->create(['console_organization_id' => $this->orgId]);

        $u40 = $this->service->dispatch([
            'type' => 'test.urgent.40',
            'recipients' => [$user],
            'organization_id' => $this->orgId,
            'priority' => NotificationPriorityEnum::Urgent,
            'idempotency_key' => 'urgent-40-'.Str::random(4),
        ]);
        Notification::where('id', $u40->id)->update(['created_at' => now()->subDays(40)]);

        $u100 = $this->service->dispatch([
            'type' => 'test.urgent.100',
            'recipients' => [$user],
            'organization_id' => $this->orgId,
            'priority' => NotificationPriorityEnum::Urgent,
            'idempotency_key' => 'urgent-100-'.Str::random(4),
        ]);
        Notification::where('id', $u100->id)->update(['created_at' => now()->subDays(100)]);

        $prunableIds = (new Notification)->prunable()->pluck('id')->all();

        expect($prunableIds)->not->toContain($u40->id)
            ->and($prunableIds)->toContain($u100->id);
    });

    it('prunes low-priority rows after just 7 days', function () {
        $user = User::factory()->create(['console_organization_id' => $this->orgId]);

        $low = $this->service->dispatch([
            'type' => 'test.low',
            'recipients' => [$user],
            'organization_id' => $this->orgId,
            'priority' => NotificationPriorityEnum::Low,
            'idempotency_key' => 'low-'.Str::random(4),
        ]);
        Notification::where('id', $low->id)->update(['created_at' => now()->subDays(8)]);

        $prunableIds = (new Notification)->prunable()->pluck('id')->all();

        expect($prunableIds)->toContain($low->id);
    });
});

// =========================================================================
//  forRecipient scope
// =========================================================================

describe('NotificationRecipient::forRecipient scope', function () {
    it('filters by recipient_type + recipient_id of the given model', function () {
        $alice = User::factory()->create(['console_organization_id' => $this->orgId]);
        $bob = User::factory()->create(['console_organization_id' => $this->orgId]);

        $this->service->dispatch([
            'type' => 'test.scope',
            'recipients' => [$alice],
            'organization_id' => $this->orgId,
            'idempotency_key' => 'scope-alice-'.Str::random(4),
        ]);
        $this->service->dispatch([
            'type' => 'test.scope',
            'recipients' => [$bob],
            'organization_id' => $this->orgId,
            'idempotency_key' => 'scope-bob-'.Str::random(4),
        ]);

        expect(NotificationRecipient::forRecipient($alice)->count())->toBe(1)
            ->and(NotificationRecipient::forRecipient($bob)->count())->toBe(1);
    });

    it('works polymorphically — same scope on Device recipients', function () {
        $device = Device::factory()->create([
            'organization_id' => $this->orgId,
            'pairing_code' => 'SCOPE1',
        ]);

        $this->service->dispatch([
            'type' => 'test.device',
            'recipients' => [$device],
            'organization_id' => $this->orgId,
            'idempotency_key' => 'scope-device-'.Str::random(4),
        ]);

        expect(NotificationRecipient::forRecipient($device)->count())->toBe(1)
            ->and(NotificationRecipient::forRecipient($device)->first()->recipient_type)
            ->toBe('Device');
    });
});
