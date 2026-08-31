<?php

/**
 * Plan-023 M4 T4.5 + T4.6 — POST /api/v1/webhooks/mail/{provider} contract.
 *
 * Scenarios:
 *   M4-7: valid signature → 202 + ApplyEmailEventJob dispatched
 *   M4-8: signature mismatch → 401 + webhook_signature_mismatch
 *   - missing secret → 401 + webhook_secret_missing
 *   - replay > 5 min → 401 + webhook_replay_window_exceeded
 *   - unknown RecordType → 202 + accepted: 0
 *   - unknown provider → 404
 *   - bounce job updates delivery.status + creates suppression
 */

use App\Jobs\Notification\ApplyEmailEventJob;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationEmailSuppression;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.postmark.webhook_secret' => 'test-secret']);
    // Force sync queue so dispatched jobs (ApplyEmailEventJob) run inline.
    // The container ships QUEUE_CONNECTION=redis which overrides phpunit.xml;
    // assert against post-job state directly.
    config(['queue.default' => 'sync']);
});

function postmarkPayload(array $overrides = []): array
{
    return array_merge([
        'RecordType' => 'Delivery',
        'MessageID' => 'msg-test-'.Str::random(6),
        'Recipient' => 'recipient@example.com',
        'DeliveredAt' => now()->subSeconds(5)->toIso8601String(),
        'Details' => 'smtp;250 OK',
    ], $overrides);
}

function signedBody(array $payload, string $secret = 'test-secret'): array
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $sig = base64_encode(hash_hmac('sha1', $json, $secret, true));

    return [$json, $sig];
}

/**
 * Post raw JSON body with X-Postmark-Signature header using `call()` so
 * the controller's `$request->getContent()` sees exactly the bytes we
 * signed. `withHeaders()` doesn't flow into raw call(), so headers go
 * through the $server array.
 */
function postMailWebhook(string $url, string $body, ?string $sig): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($sig !== null) {
        $server['HTTP_X_POSTMARK_SIGNATURE'] = $sig;
    }

    return test()->call('POST', $url, [], [], [], $server, $body);
}

it('M4-7: valid signature dispatches ApplyEmailEventJob and returns 202', function () {
    Bus::fake([ApplyEmailEventJob::class]);

    [$json, $sig] = signedBody(postmarkPayload());

    postMailWebhook('/api/v1/webhooks/mail/postmark', $json, $sig)
        ->assertStatus(202)
        ->assertJson(['accepted' => 1]);

    Bus::assertDispatched(ApplyEmailEventJob::class, 1);
});

it('M4-8: signature mismatch returns 401', function () {
    Bus::fake([ApplyEmailEventJob::class]);

    [$json] = signedBody(postmarkPayload());

    postMailWebhook('/api/v1/webhooks/mail/postmark', $json, 'bogus')
        ->assertStatus(401)
        ->assertJson(['error' => 'webhook_signature_mismatch']);

    Bus::assertNotDispatched(ApplyEmailEventJob::class);
});

it('rejects when no signature header is present', function () {
    [$json] = signedBody(postmarkPayload());

    postMailWebhook('/api/v1/webhooks/mail/postmark', $json, null)
        ->assertStatus(401)
        ->assertJson(['error' => 'webhook_signature_missing']);
});

it('rejects replay > 5 minutes', function () {
    Bus::fake([ApplyEmailEventJob::class]);

    [$json, $sig] = signedBody(postmarkPayload(['DeliveredAt' => now()->subMinutes(10)->toIso8601String()]));

    postMailWebhook('/api/v1/webhooks/mail/postmark', $json, $sig)
        ->assertStatus(401)
        ->assertJson(['error' => 'webhook_replay_window_exceeded']);
});

it('returns 202 + accepted=0 for unknown RecordType', function () {
    Bus::fake([ApplyEmailEventJob::class]);

    [$json, $sig] = signedBody(postmarkPayload(['RecordType' => 'ChatMessage']));

    postMailWebhook('/api/v1/webhooks/mail/postmark', $json, $sig)
        ->assertStatus(202)
        ->assertJsonPath('accepted', 0);

    Bus::assertNotDispatched(ApplyEmailEventJob::class);
});

it('returns 404 for unregistered provider', function () {
    postMailWebhook('/api/v1/webhooks/mail/skywriter', '{}', null)
        ->assertStatus(404)
        ->assertJson(['error' => 'webhook_unknown_provider']);
});

it('M4-9: bounce event updates delivery + creates suppression', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $user = User::factory()->create([
        'console_organization_id' => $orgId,
        'email' => 'bouncer@example.com',
    ]);

    $notification = Notification::factory()->create([
        'organization_id' => $orgId,
        'type' => 'stock.alert.low',
    ]);
    $recipient = NotificationRecipient::factory()->create([
        'notification_id' => $notification->id,
        'recipient_type' => 'User',
        'recipient_id' => $user->id,
    ]);
    $delivery = NotificationDelivery::query()->create([
        'id' => (string) Str::uuid(),
        'notification_recipient_id' => $recipient->id,
        'channel' => 'email',
        'status' => 'sent',
        'provider_ref' => 'msg-bounce-1',
        'attempts' => 1,
        'sent_at' => now()->subMinute(),
    ]);

    [$json, $sig] = signedBody([
        'RecordType' => 'HardBounce',
        'MessageID' => 'msg-bounce-1',
        'Email' => 'BOUNCER@example.com',
        'BouncedAt' => now()->subSeconds(30)->toIso8601String(),
        'Details' => '550 user unknown',
    ]);

    postMailWebhook('/api/v1/webhooks/mail/postmark', $json, $sig)->assertStatus(202);

    $fresh = $delivery->fresh();
    $statusValue = $fresh->status instanceof BackedEnum ? $fresh->status->value : (string) $fresh->status;
    expect($statusValue)->toBe('bounced');
    expect($delivery->fresh()->failed_at)->not->toBeNull();
    expect(NotificationEmailSuppression::query()->where('email', 'bouncer@example.com')->where('reason', 'hard_bounce')->exists())->toBeTrue();
});

it('M4-10: complaint event creates spam_complaint suppression', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $user = User::factory()->create([
        'console_organization_id' => $orgId,
        'email' => 'spammed@example.com',
    ]);

    $notification = Notification::factory()->create([
        'organization_id' => $orgId,
        'type' => 'stock.alert.low',
    ]);
    $recipient = NotificationRecipient::factory()->create([
        'notification_id' => $notification->id,
        'recipient_type' => 'User',
        'recipient_id' => $user->id,
    ]);
    NotificationDelivery::query()->create([
        'id' => (string) Str::uuid(),
        'notification_recipient_id' => $recipient->id,
        'channel' => 'email',
        'status' => 'sent',
        'provider_ref' => 'msg-spam-1',
    ]);

    [$json, $sig] = signedBody([
        'RecordType' => 'SpamComplaint',
        'MessageID' => 'msg-spam-1',
        'Email' => 'spammed@example.com',
        'ReceivedAt' => now()->subSeconds(10)->toIso8601String(),
        'Details' => 'FBL',
    ]);

    postMailWebhook('/api/v1/webhooks/mail/postmark', $json, $sig)->assertStatus(202);

    expect(NotificationEmailSuppression::query()->where('email', 'spammed@example.com')->where('reason', 'spam_complaint')->exists())->toBeTrue();
});
