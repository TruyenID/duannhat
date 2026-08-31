<?php

/**
 * GDPR right-to-be-forgotten endpoint (issue #179 part C).
 *
 * `DELETE /api/v1/me/notifications` — purges the caller's NotificationRecipient
 * rows + cascading NotificationDelivery children. Other recipients of the
 * same parent Notification are NOT affected.
 *
 * Pins:
 *   - Wipes own inbox, returns count
 *   - Does NOT touch other users' notifications (cross-user isolation)
 *   - Does NOT delete the parent Notification row (other recipients keep theirs)
 *   - Cascades through to NotificationDelivery
 *   - Idempotent — second call returns 0
 *   - 401 without auth
 *   - 429 after rate limit
 */

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notification\NotificationService;
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
    $this->service = app(NotificationService::class);
    $this->base = '/api/v1/me/notifications';
});

it('wipes the caller\'s entire inbox and returns the count', function () {
    // Three notifications, all addressed to this user.
    for ($i = 0; $i < 3; $i++) {
        $this->service->dispatch([
            'type' => 'recipe.approved',
            'template_key' => 'recipe.approved',
            'recipients' => [$this->user],
            'organization_id' => $this->orgId,
            'idempotency_key' => 'gdpr-'.$i.'-'.Str::random(4),
        ]);
    }

    expect(NotificationRecipient::forRecipient($this->user)->count())->toBe(3);

    $response = $this->actingAs($this->user)
        ->deleteJson($this->base)
        ->assertOk();

    expect($response->json('data.wiped_count'))->toBe(3)
        ->and(NotificationRecipient::forRecipient($this->user)->count())->toBe(0);
});

it('does not touch notifications addressed to other users', function () {
    $otherUser = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->service->dispatch([
        'type' => 'recipe.approved',
        'recipients' => [$otherUser],
        'organization_id' => $this->orgId,
        'idempotency_key' => 'gdpr-other-'.Str::random(4),
    ]);
    // Mine + theirs share no rows.
    $this->service->dispatch([
        'type' => 'recipe.approved',
        'recipients' => [$this->user],
        'organization_id' => $this->orgId,
        'idempotency_key' => 'gdpr-mine-'.Str::random(4),
    ]);

    $this->actingAs($this->user)->deleteJson($this->base)->assertOk();

    expect(NotificationRecipient::forRecipient($this->user)->count())->toBe(0)
        ->and(NotificationRecipient::forRecipient($otherUser)->count())->toBe(1);
});

it('preserves the parent Notification row when other users still need it', function () {
    $otherUser = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    // ONE notification fanned out to both users.
    $n = $this->service->dispatch([
        'type' => 'recipe.approved',
        'recipients' => [$this->user, $otherUser],
        'organization_id' => $this->orgId,
        'idempotency_key' => 'gdpr-shared-'.Str::random(4),
    ]);

    $this->actingAs($this->user)->deleteJson($this->base)->assertOk();

    // Parent notification still exists; the other user's recipient row
    // is intact and points at it.
    expect(Notification::find($n->id))->not->toBeNull()
        ->and(NotificationRecipient::forRecipient($otherUser)->where('notification_id', $n->id)->count())->toBe(1)
        ->and(NotificationRecipient::forRecipient($this->user)->count())->toBe(0);
});

it('cascades through to NotificationDelivery rows', function () {
    $n = $this->service->dispatch([
        'type' => 'recipe.approved',
        'template_key' => 'recipe.approved',
        'recipients' => [$this->user],
        'organization_id' => $this->orgId,
        'idempotency_key' => 'gdpr-cascade-'.Str::random(4),
    ]);

    $recipientId = NotificationRecipient::forRecipient($this->user)
        ->where('notification_id', $n->id)
        ->value('id');
    $deliveryCount = NotificationDelivery::where('notification_recipient_id', $recipientId)->count();
    expect($deliveryCount)->toBeGreaterThan(0);

    $this->actingAs($this->user)->deleteJson($this->base)->assertOk();

    // Recipient and its deliveries are both gone.
    expect(NotificationDelivery::where('notification_recipient_id', $recipientId)->count())->toBe(0);
});

it('is idempotent — second call returns wiped_count=0', function () {
    $this->service->dispatch([
        'type' => 'recipe.approved',
        'recipients' => [$this->user],
        'organization_id' => $this->orgId,
        'idempotency_key' => 'gdpr-idem-'.Str::random(4),
    ]);

    $this->actingAs($this->user)->deleteJson($this->base)->assertOk();

    $second = $this->actingAs($this->user)->deleteJson($this->base)->assertOk();
    expect($second->json('data.wiped_count'))->toBe(0);
});

it('returns 401 without auth', function () {
    $this->deleteJson($this->base)->assertUnauthorized();
});

it('rate-limits to 5/min (sensitive irreversible action)', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($this->user)->deleteJson($this->base)->assertOk();
    }

    $this->actingAs($this->user)->deleteJson($this->base)->assertStatus(429);
});
