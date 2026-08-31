<?php

/**
 * Email-health aggregate metrics: sent / delivered from
 * notification_deliveries (email channel) + bounced / spam from
 * notification_email_suppressions, scoped to the brand's organizations
 * and an optional date window.
 */

use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationEmailSuppression;
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
        'slug' => 'health-hq-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->url = "/api/v1/hq/{$this->brand->slug}/notifications/email-health/metrics";
});

function seedEmailDelivery(string $orgId, ?DateTimeInterface $sentAt, ?DateTimeInterface $deliveredAt): NotificationDelivery
{
    $notification = Notification::factory()->create(['organization_id' => $orgId]);
    $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id]);

    return NotificationDelivery::query()->create([
        'id' => (string) Str::uuid(),
        'notification_recipient_id' => $recipient->id,
        'channel' => 'email',
        'status' => $deliveredAt ? 'delivered' : ($sentAt ? 'sent' : 'pending'),
        'attempts' => 1,
        'sent_at' => $sentAt,
        'delivered_at' => $deliveredAt,
    ]);
}

it('aggregates sent/delivered/bounced/spam within the default 30-day window', function () {
    // In-window deliveries.
    seedEmailDelivery($this->orgId, now()->subDays(2), now()->subDays(2));
    seedEmailDelivery($this->orgId, now()->subDays(5), null);

    // Out-of-window — must NOT be counted.
    seedEmailDelivery($this->orgId, now()->subDays(60), now()->subDays(60));

    // Non-email channel — must NOT be counted.
    $n = Notification::factory()->create(['organization_id' => $this->orgId]);
    $r = NotificationRecipient::factory()->create(['notification_id' => $n->id]);
    NotificationDelivery::query()->create([
        'id' => (string) Str::uuid(),
        'notification_recipient_id' => $r->id,
        'channel' => 'push',
        'status' => 'sent',
        'sent_at' => now()->subDay(),
    ]);

    foreach ([
        ['email' => 'b1@example.com', 'reason' => 'hard_bounce', 'at' => now()->subDays(3)],
        ['email' => 'b2@example.com', 'reason' => 'hard_bounce', 'at' => now()->subDays(40)], // out-of-window
        ['email' => 's1@example.com', 'reason' => 'spam_complaint', 'at' => now()->subDay()],
        ['email' => 'u1@example.com', 'reason' => 'subscription_change', 'at' => now()->subDays(2)],
        ['email' => 'u2@example.com', 'reason' => 'subscription_change', 'at' => now()->subDays(4)],
        ['email' => 'm1@example.com', 'reason' => 'manual', 'at' => now()->subDay()],
    ] as $seed) {
        NotificationEmailSuppression::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->orgId,
            'email' => $seed['email'],
            'reason' => $seed['reason'],
            'source_provider' => 'postmark',
            'suppressed_at' => $seed['at'],
        ]);
    }

    $this->actingAs($this->user)->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.sent', 2)
        ->assertJsonPath('data.delivered', 1)
        ->assertJsonPath('data.bounced', 1)
        ->assertJsonPath('data.spam', 1)
        ->assertJsonPath('data.unsubscribed', 2)
        ->assertJsonStructure(['meta' => ['from', 'to']]);
});

it('respects custom from/to query params', function () {
    seedEmailDelivery($this->orgId, now()->subDays(10), now()->subDays(10));
    seedEmailDelivery($this->orgId, now()->subDays(2), now()->subDays(2));

    $from = urlencode(now()->subDays(5)->toIso8601String());
    $to = urlencode(now()->toIso8601String());

    $this->actingAs($this->user)->getJson("{$this->url}?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.sent', 1)
        ->assertJsonPath('data.delivered', 1);
});

it('does NOT leak metrics from other organizations', function () {
    $otherOrg = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrg, 'console_organization_id' => $otherOrg]);

    seedEmailDelivery($otherOrg, now()->subDay(), now()->subDay());
    NotificationEmailSuppression::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $otherOrg,
        'email' => 'leaky@example.com',
        'reason' => 'hard_bounce',
        'source_provider' => 'postmark',
        'suppressed_at' => now(),
    ]);

    $this->actingAs($this->user)->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.sent', 0)
        ->assertJsonPath('data.delivered', 0)
        ->assertJsonPath('data.bounced', 0)
        ->assertJsonPath('data.spam', 0)
        ->assertJsonPath('data.unsubscribed', 0);
});

it('requires the manage-suppressions ability', function () {
    $stranger = User::factory()->create([]);
    $this->actingAs($stranger)->getJson($this->url)->assertForbidden();
});

it('timeseries returns one bucket per day with counts', function () {
    $tsUrl = "/api/v1/hq/{$this->brand->slug}/notifications/email-health/metrics/timeseries";

    // 2 sent on day-2, 1 delivered on day-2, 1 sent on day-5.
    seedEmailDelivery($this->orgId, now()->subDays(2), now()->subDays(2));
    seedEmailDelivery($this->orgId, now()->subDays(2), null);
    seedEmailDelivery($this->orgId, now()->subDays(5), null);

    // 1 hard_bounce on day-3, 1 spam on day-1.
    NotificationEmailSuppression::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'email' => 'b@example.com',
        'reason' => 'hard_bounce',
        'source_provider' => 'postmark',
        'suppressed_at' => now()->subDays(3),
    ]);
    NotificationEmailSuppression::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'email' => 's@example.com',
        'reason' => 'spam_complaint',
        'source_provider' => 'postmark',
        'suppressed_at' => now()->subDay(),
    ]);

    // Un-suppressed bounce — must NOT count.
    NotificationEmailSuppression::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'email' => 'gone@example.com',
        'reason' => 'hard_bounce',
        'source_provider' => 'postmark',
        'suppressed_at' => now()->subDays(2),
        'un_suppressed_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($this->user)->getJson($tsUrl)
        ->assertOk()
        ->assertJsonStructure(['meta' => ['from', 'to'], 'data']);

    $body = $response->json();
    // 31 buckets for the default 30-day window (from..to inclusive)
    expect(count($body['data']))->toBeGreaterThanOrEqual(30);

    // Sum across all buckets should match the aggregate counts.
    $totals = ['sent' => 0, 'delivered' => 0, 'bounced' => 0, 'spam' => 0];
    foreach ($body['data'] as $row) {
        foreach ($totals as $k => $_) {
            $totals[$k] += $row[$k];
        }
    }
    expect($totals)->toEqual([
        'sent' => 3,
        'delivered' => 1,
        'bounced' => 1, // un-suppressed bounce was excluded
        'spam' => 1,
    ]);
});

it('does NOT count un-suppressed rows toward bounced/spam', function () {
    // Active hard_bounce — counts.
    NotificationEmailSuppression::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'email' => 'still-blocked@example.com',
        'reason' => 'hard_bounce',
        'source_provider' => 'postmark',
        'suppressed_at' => now()->subDays(2),
    ]);
    // Un-suppressed hard_bounce — does NOT count.
    NotificationEmailSuppression::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'email' => 'recovered@example.com',
        'reason' => 'hard_bounce',
        'source_provider' => 'postmark',
        'suppressed_at' => now()->subDays(3),
        'un_suppressed_at' => now()->subDay(),
    ]);
    // Un-suppressed spam — does NOT count.
    NotificationEmailSuppression::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'email' => 'forgiven@example.com',
        'reason' => 'spam_complaint',
        'source_provider' => 'postmark',
        'suppressed_at' => now()->subDays(2),
        'un_suppressed_at' => now(),
    ]);

    $this->actingAs($this->user)->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.bounced', 1)
        ->assertJsonPath('data.spam', 0);
});
