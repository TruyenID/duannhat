<?php

use App\Exceptions\NotificationException;
use App\Models\Device;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationPriorityEnum;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
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

    $this->service = app(NotificationService::class);
});

/**
 * Seeds one notification with the current test user as the sole recipient.
 */
function seedOneForUser($user, $orgId, array $overrides = []): Notification
{
    return app(NotificationService::class)->dispatch(array_merge([
        'type' => 'recipe.approved',
        'template_key' => 'recipe.approved',
        'params' => ['recipe_name' => 'Gyoza', 'approver' => 'Tanaka'],
        'recipients' => [$user],
        'actor' => null,
        'organization_id' => $orgId,
        'idempotency_key' => 'test-'.Str::random(8),
    ], $overrides));
}

// =========================================================================
//  Happy path
// =========================================================================

describe('GET /me/notifications', function () {
    it('lists the authenticated user inbox with the recipient block populated', function () {
        seedOneForUser($this->user, $this->orgId);

        // Plan-023 M5 — `?collapse=false` requests the flat plan-008 shape
        // since this test asserts the per-row `type` + `recipient` fields
        // directly. The collapsed shape moves those under `data.0.latest`.
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/me/notifications?collapse=false');

        $response->assertOk()
            ->assertJsonPath('data.0.type', 'recipe.approved')
            ->assertJsonPath('data.0.recipient.read_at', null)
            ->assertJsonPath('meta.total', 1);
    });

    it('filters by status=unread', function () {
        $n = seedOneForUser($this->user, $this->orgId);
        $row = NotificationRecipient::forRecipient($this->user)
            ->where('notification_id', $n->id)
            ->first();
        $row->markRead();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/me/notifications?status=unread&collapse=false');

        $response->assertOk()->assertJsonPath('meta.total', 0);
    });

    it('excludes dismissed rows by default', function () {
        $n = seedOneForUser($this->user, $this->orgId);
        NotificationRecipient::forRecipient($this->user)
            ->where('notification_id', $n->id)
            ->first()
            ->markDismissed();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/me/notifications?collapse=false');

        $response->assertOk()->assertJsonPath('meta.total', 0);
    });
});

describe('GET /me/notifications/summary', function () {
    it('returns unread count + priority breakdown', function () {
        seedOneForUser($this->user, $this->orgId);
        seedOneForUser($this->user, $this->orgId, [
            'type' => 'system.critical',
            'template_key' => 'system.critical',
            'priority' => NotificationPriorityEnum::Urgent,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/me/notifications/summary');

        $response->assertOk()
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonPath('data.priority_breakdown.urgent', 1)
            ->assertJsonPath('data.priority_breakdown.normal', 1);
    });
});

describe('PATCH /me/notifications/{id}/read', function () {
    it('sets read_at + seen_at and is idempotent', function () {
        $n = seedOneForUser($this->user, $this->orgId);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/me/notifications/{$n->id}/read")
            ->assertNoContent();

        $row = NotificationRecipient::forRecipient($this->user)
            ->where('notification_id', $n->id)
            ->first();

        expect($row->read_at)->not->toBeNull()
            ->and($row->seen_at)->not->toBeNull();

        $firstRead = $row->read_at;

        // Second call — idempotent.
        $this->actingAs($this->user)
            ->patchJson("/api/v1/me/notifications/{$n->id}/read")
            ->assertNoContent();

        expect($row->fresh()->read_at->eq($firstRead))->toBeTrue();
    });
});

describe('POST /me/notifications/bulk-read', function () {
    it('marks every unread row as read when all_unread=true', function () {
        seedOneForUser($this->user, $this->orgId);
        seedOneForUser($this->user, $this->orgId, ['type' => 'stock.alert.low']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/me/notifications/bulk-read', [
                'all_unread' => true,
            ]);

        $response->assertOk()->assertJsonPath('data.marked_count', 2);

        $unread = NotificationRecipient::forRecipient($this->user)->unread()->count();
        expect($unread)->toBe(0);
    });
});

// =========================================================================
//  Authorization
// =========================================================================

describe('authorization', function () {
    it('returns 401 when unauthenticated', function () {
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
    });

    it('returns 404 when the caller is not a recipient of the notification', function () {
        $otherUser = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        $n = seedOneForUser($otherUser, $this->orgId);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/me/notifications/{$n->id}/read")
            ->assertNotFound();
    });

    it('does not leak notifications addressed to other users in /me/notifications', function () {
        $otherUser = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        seedOneForUser($otherUser, $this->orgId);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/me/notifications');

        $response->assertOk()->assertJsonPath('meta.total', 0);
    });
});

// =========================================================================
//  Validation
// =========================================================================

describe('validation', function () {
    it('returns 422 when status is not an enum value', function () {
        $this->actingAs($this->user)
            ->getJson('/api/v1/me/notifications?status=invalid')
            ->assertStatus(422);
    });

    it('returns 422 when per_page exceeds the cap', function () {
        $this->actingAs($this->user)
            ->getJson('/api/v1/me/notifications?per_page=500')
            ->assertStatus(422);
    });

    it('returns 422 when neither ids nor all_unread is supplied to bulk-read', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/notifications/bulk-read', [])
            ->assertStatus(422);
    });

    it('returns 422 when both ids and all_unread are supplied to bulk-read', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/notifications/bulk-read', [
                'ids' => [(string) Str::uuid()],
                'all_unread' => true,
            ])
            ->assertStatus(422);
    });
});

// =========================================================================
//  Morph
// =========================================================================

describe('morph map', function () {
    it('stores recipient_type as the TitleCase morph alias, not the class path', function () {
        $n = seedOneForUser($this->user, $this->orgId);

        $row = NotificationRecipient::where('notification_id', $n->id)->first();

        expect($row->recipient_type)->toBe('User')
            ->and($row->recipient_type)->not->toBe(User::class);
    });

    it('allows Device as a Notifiable recipient', function () {
        $device = Device::factory()->create([
            'organization_id' => $this->orgId,
            'pairing_code' => 'ABC123',
        ]);

        $n = $this->service->dispatch([
            'type' => 'device.ping',
            'template_key' => 'device.ping',
            'params' => [],
            'recipients' => [$device],
            'actor' => null,
            'organization_id' => $this->orgId,
        ]);

        $row = NotificationRecipient::where('notification_id', $n->id)->first();

        expect($row->recipient_type)->toBe('Device')
            ->and($row->recipient->is($device))->toBeTrue();
    });

    it('stores actor=null for system events', function () {
        $n = seedOneForUser($this->user, $this->orgId);

        expect($n->actor_type)->toBeNull()
            ->and($n->actor_id)->toBeNull()
            ->and($n->actor)->toBeNull();
    });
});

// =========================================================================
//  Idempotency
// =========================================================================

describe('idempotency', function () {
    it('does not duplicate notifications or recipients when re-dispatching with the same key', function () {
        $key = 'idempotency-probe-'.Str::random(6);

        $first = $this->service->dispatch([
            'type' => 'recipe.approved',
            'recipients' => [$this->user],
            'organization_id' => $this->orgId,
            'idempotency_key' => $key,
        ]);

        $second = $this->service->dispatch([
            'type' => 'recipe.approved',
            'recipients' => [$this->user],
            'organization_id' => $this->orgId,
            'idempotency_key' => $key,
        ]);

        expect($second->id)->toBe($first->id)
            ->and(Notification::where('idempotency_key', $key)->count())->toBe(1)
            ->and(NotificationRecipient::where('notification_id', $first->id)->count())->toBe(1);
    });

    it('rejects empty recipients with a 422 NotificationException', function () {
        expect(fn () => $this->service->dispatch([
            'type' => 'test',
            'recipients' => [],
            'organization_id' => $this->orgId,
        ]))->toThrow(NotificationException::class);
    });

    it('dedupes duplicate recipient references at service level', function () {
        $n = $this->service->dispatch([
            'type' => 'recipe.approved',
            'recipients' => [$this->user, $this->user, $this->user],
            'organization_id' => $this->orgId,
            'idempotency_key' => 'dedup-test-'.Str::random(6),
        ]);

        expect(NotificationRecipient::where('notification_id', $n->id)->count())->toBe(1);
    });
});

// =============================================================================
// Race-condition guards (issue #172)
// =============================================================================

describe('mark-state atomicity (issue #172)', function () {
    // Simulates a race: caller has a stale in-memory copy where seen_at /
    // read_at is null, but the DB row has already been timestamped (a
    // concurrent worker won the race). Pre-fix, the check-then-update path
    // would have happily UPDATEd the DB based on the stale local state and
    // overwritten the FIRST writer's timestamp. Post-fix, the atomic UPDATE
    // with COALESCE preserves the DB value regardless of in-memory state.

    it('markSeen does not overwrite an existing seen_at (atomic COALESCE)', function () {
        $n = seedOneForUser($this->user, $this->orgId);
        $row = NotificationRecipient::query()
            ->where('notification_id', $n->id)
            ->where('recipient_id', $this->user->id)
            ->firstOrFail();

        $past = now()->subHour();
        DB::table('notification_recipients')
            ->where('id', $row->id)
            ->update(['seen_at' => $past]);

        $row->seen_at = null; // stale in-memory copy
        $row->markSeen();

        $persisted = $row->fresh()->seen_at;
        expect($persisted)->not->toBeNull()
            ->and(abs($persisted->diffInSeconds($past)))->toBeLessThan(2);
    });

    it('markRead does not overwrite an existing read_at (atomic COALESCE)', function () {
        $n = seedOneForUser($this->user, $this->orgId);
        $row = NotificationRecipient::query()
            ->where('notification_id', $n->id)
            ->where('recipient_id', $this->user->id)
            ->firstOrFail();

        $past = now()->subHour();
        DB::table('notification_recipients')
            ->where('id', $row->id)
            ->update(['seen_at' => $past, 'read_at' => $past]);

        $row->seen_at = null;
        $row->read_at = null; // stale in-memory copy
        $row->markRead();

        $persisted = $row->fresh();
        expect(abs($persisted->seen_at->diffInSeconds($past)))->toBeLessThan(2)
            ->and(abs($persisted->read_at->diffInSeconds($past)))->toBeLessThan(2);
    });

    it('markDismissed does not overwrite an existing dismissed_at (atomic COALESCE)', function () {
        $n = seedOneForUser($this->user, $this->orgId);
        $row = NotificationRecipient::query()
            ->where('notification_id', $n->id)
            ->where('recipient_id', $this->user->id)
            ->firstOrFail();

        $past = now()->subHour();
        DB::table('notification_recipients')
            ->where('id', $row->id)
            ->update(['dismissed_at' => $past]);

        $row->dismissed_at = null;
        $row->markDismissed();

        expect(abs($row->fresh()->dismissed_at->diffInSeconds($past)))->toBeLessThan(2);
    });
});

describe('idempotency lock (issue #172)', function () {
    it('two dispatches with the same idempotency_key return the same row', function () {
        $key = 'idem-lock-'.Str::random(6);

        $first = $this->service->dispatch([
            'type' => 'recipe.approved',
            'recipients' => [$this->user],
            'organization_id' => $this->orgId,
            'idempotency_key' => $key,
        ]);
        $second = $this->service->dispatch([
            'type' => 'recipe.approved',
            'recipients' => [$this->user],
            'organization_id' => $this->orgId,
            'idempotency_key' => $key,
        ]);

        // Same Notification row, single recipient row — fully idempotent.
        expect($second->id)->toBe($first->id)
            ->and(Notification::where('idempotency_key', $key)->count())->toBe(1)
            ->and(NotificationRecipient::where('notification_id', $first->id)->count())->toBe(1);
    });
});
