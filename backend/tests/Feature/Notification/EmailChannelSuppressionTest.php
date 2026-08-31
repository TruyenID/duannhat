<?php

/**
 * Plan-023 M4 T4.7 — EmailChannel::send short-circuits on suppression.
 *
 * Pre-M4 EmailChannel called Mail::to() unconditionally; bounced
 * addresses kept getting mail. Post-M4 the channel queries
 * notification_email_suppressions before sending and returns a
 * skipped result without touching SMTP when (org, email) is hit.
 */

use App\Models\Notification;
use App\Models\NotificationEmailSuppression;
use App\Models\NotificationRecipient;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notification\Channels\EmailChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function buildEmailFixture(string $orgId, string $email, ?string $suppressedAt = 'now', ?string $unSuppressedAt = null): array
{
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $user = User::factory()->create([
        'console_organization_id' => $orgId,
        'email' => $email,
    ]);

    $template = NotificationTemplate::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgId,
        'key' => 'stock.alert.low',
        'content' => ['ja' => ['title' => 'Tê', 'body' => 'Bê'], 'en' => ['title' => 'T', 'body' => 'B'], 'vi' => ['title' => 'T', 'body' => 'B']],
        'default_channels' => ['in_app', 'email'],
        'is_system' => false,
    ]);

    $notification = Notification::factory()->create([
        'organization_id' => $orgId,
        'type' => 'stock.alert.low',
        'template_key' => 'stock.alert.low',
    ]);

    $recipient = NotificationRecipient::factory()->create([
        'notification_id' => $notification->id,
        'recipient_type' => 'User',
        'recipient_id' => $user->id,
    ]);

    if ($suppressedAt !== null) {
        NotificationEmailSuppression::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $orgId,
            'email' => strtolower($email),
            'reason' => 'hard_bounce',
            'source_provider' => 'postmark',
            'suppressed_at' => $suppressedAt === 'now' ? now() : $suppressedAt,
            'un_suppressed_at' => $unSuppressedAt,
        ]);
    }

    return ['notification' => $notification, 'recipient' => $recipient, 'user' => $user, 'template' => $template];
}

it('M4-11: suppressed email is skipped before SMTP', function () {
    Mail::fake();
    $orgId = (string) Str::uuid();

    $fixtures = buildEmailFixture($orgId, 'blocked@example.com');

    $result = app(EmailChannel::class)->send($fixtures['notification']->fresh(), $fixtures['recipient']);

    expect($result->status->value)->toBe('skipped');
    expect($result->error ?? '')->toContain('suppression list');
    Mail::assertNothingSent();
});

it('un-suppressed addresses are sent normally', function () {
    Mail::fake();
    $orgId = (string) Str::uuid();

    $fixtures = buildEmailFixture(
        $orgId,
        'cleared@example.com',
        suppressedAt: now()->subDay()->toDateTimeString(),
        unSuppressedAt: now()->subMinute()->toDateTimeString(),
    );

    $result = app(EmailChannel::class)->send($fixtures['notification']->fresh(), $fixtures['recipient']);

    expect($result->status->value)->toBe('sent');
});

it('no suppression row → email is sent normally', function () {
    Mail::fake();
    $orgId = (string) Str::uuid();

    $fixtures = buildEmailFixture($orgId, 'open@example.com', suppressedAt: null);

    $result = app(EmailChannel::class)->send($fixtures['notification']->fresh(), $fixtures['recipient']);

    expect($result->status->value)->toBe('sent');
});
