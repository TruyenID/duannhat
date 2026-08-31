<?php

/**
 * #130 P2 — HQ/NotificationAdminController coverage
 *
 * Endpoints (auth: sso, scope: brand):
 *   GET    /hq/{brandSlug}/notifications/types        — type catalog (lookup)
 *   GET    /hq/{brandSlug}/notifications              — paginated list
 *   GET    /hq/{brandSlug}/notifications/{id}         — single notification
 *   DELETE /hq/{brandSlug}/notifications/{id}         — soft delete
 */

use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'hqn-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications";
});

it('returns paginated notifications for the brand', function () {
    Notification::factory()->count(2)->create([
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

it('returns a single notification by id', function () {
    $notification = Notification::factory()->create([
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $notification->id);
});

it('returns 404 for non-existent notification id', function () {
    $this->actingAs($this->user)
        ->getJson("{$this->base}/".Str::uuid())
        ->assertNotFound();
});

it('returns 422 (or similar non-2xx) when destroy receives non-existent id', function () {
    // Hidden rule: notification destroy requires the row to belong to the
    // brand AND be in a deletable state. Service throws ValidationException
    // when neither holds. Pinning behaviour rather than trying to seed a
    // deletable fixture (that needs full notification audience graph).
    $response = $this->actingAs($this->user)
        ->deleteJson("{$this->base}/".Str::uuid());
    expect($response->status())->toBeIn([404, 422]);
});

it('returns the notification type catalog', function () {
    $this->actingAs($this->user)
        ->getJson("{$this->base}/types")
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('returns 401 without auth on index', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

it('returns 401 without auth on types', function () {
    $this->getJson("{$this->base}/types")->assertUnauthorized();
});

// =============================================================================
// Quick stats — sent / scheduled / failed (TC-NOTIF-ROOT04)
// =============================================================================

describe('quick stats', function () {
    it('returns sent / scheduled / failed counts scoped to the brand', function () {
        // 3 dispatched (sent), 2 scheduled (pending future).
        Notification::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'is_dispatched' => true,
            'scheduled_for' => null,
        ]);
        Notification::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'is_dispatched' => false,
            'scheduled_for' => now()->addDay(),
        ]);

        // One sent notification with a failed delivery → contributes to `failed`.
        $sent = Notification::factory()->create([
            'organization_id' => $this->orgId,
            'is_dispatched' => true,
            'scheduled_for' => null,
        ]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $sent->id,
        ]);
        NotificationDelivery::factory()->create([
            'notification_recipient_id' => $recipient->id,
            'channel' => 'email',
            'status' => 'failed',
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/stats")
            ->assertOk()
            ->assertJsonPath('data.sent', 4) // 3 + the one with a failed delivery
            ->assertJsonPath('data.scheduled', 2)
            ->assertJsonPath('data.failed', 1);
    });

    it('does not count another organization in the stats', function () {
        Notification::factory()->create([
            'organization_id' => $this->orgId,
            'is_dispatched' => true,
            'scheduled_for' => null,
        ]);

        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        Notification::factory()->count(5)->create([
            'organization_id' => $otherOrgId,
            'is_dispatched' => true,
            'scheduled_for' => null,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/stats")
            ->assertOk()
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.failed', 0);
    });

    it('returns 401 without auth on stats', function () {
        $this->getJson("{$this->base}/stats")->assertUnauthorized();
    });
});

// =============================================================================
// Cross-tenant authz pin (issue #173 part E + #174 audit)
// =============================================================================
//
// Verifies that an HQ admin authenticated against Brand A cannot see, fetch,
// or destroy notifications belonging to Brand B's organization. The query
// scoping in NotificationService::listForBrand + showForBrand already filters
// by org IDs (whereIn organization_id IN brand_orgs), so cross-tenant
// notifications are hidden — these tests pin that behavior.
//
// =============================================================================

describe('cross-tenant authz pin', function () {
    it('does not include notifications from another organization in index', function () {
        // Own brand has 2 notifications.
        Notification::factory()->count(2)->create([
            'organization_id' => $this->orgId,
        ]);

        // Foreign org has 3 — must NOT appear in our list.
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        Notification::factory()->count(3)->create([
            'organization_id' => $otherOrgId,
        ]);

        $response = $this->actingAs($this->user)->getJson($this->base)->assertOk();
        expect($response->json('data'))->toHaveCount(2);
    });

    it('returns 404 when fetching a notification from another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $foreign = Notification::factory()->create([
            'organization_id' => $otherOrgId,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/{$foreign->id}")
            ->assertNotFound();
    });

    it('returns 404 (or 422) when destroying a notification from another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $foreign = Notification::factory()->create([
            'organization_id' => $otherOrgId,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$foreign->id}");

        // Service throws ValidationException when row isn't deletable for the
        // brand; controller renders that as 422. Either response code locks
        // out cross-tenant access — what we forbid is a 200/204 success.
        expect($response->status())->toBeIn([404, 422]);
    });
});
