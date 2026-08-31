<?php

/**
 * Unit tests for the 3 Phase C channel implementations (plan-012 T3.6).
 */

use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\Channels\EmailChannel;
use App\Services\Notification\Channels\InAppChannel;
use App\Services\Notification\Channels\PushChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'email' => 'alex@example.com',
        'locale' => 'en',
    ]);

    $this->template = NotificationTemplate::query()->create([
        'key' => 'recipe.approved',
        'content' => [
            'ja' => ['title' => 'ja title', 'body' => 'ja body'],
            'en' => ['title' => 'Recipe approved: {{recipe_name}}', 'body' => 'by {{approver}}'],
            'vi' => ['title' => 'vi title', 'body' => 'vi body'],
        ],
        'is_system' => true,
    ]);

    $this->notification = Notification::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'template_key' => 'recipe.approved',
        'params' => ['recipe_name' => 'Curry', 'approver' => 'Tanaka'],
        'priority' => 'normal',
    ]);
    $this->recipient = NotificationRecipient::query()->create([
        'notification_id' => $this->notification->id,
        'recipient_type' => $this->user->getMorphClass(),
        'recipient_id' => $this->user->id,
    ]);
});

describe('InAppChannel', function () {
    it('returns status=sent synchronously without external calls', function () {
        $result = app(InAppChannel::class)->send($this->notification, $this->recipient);

        expect($result->status)->toBe(NotificationDeliveryStatusEnum::Sent);
    });
});

describe('EmailChannel', function () {
    it('sends NotificationMail with rendered title + body for recipient locale', function () {
        Mail::fake();

        $result = app(EmailChannel::class)->send($this->notification, $this->recipient);

        expect($result->status)->toBe(NotificationDeliveryStatusEnum::Sent);
        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) {
            return $mail->renderedTitle === 'Recipe approved: Curry'
                && $mail->renderedBody === 'by Tanaka'
                && $mail->hasTo('alex@example.com');
        });
    });

    it('passes the user id + notification type to NotificationMail for List-Unsubscribe (#173 A)', function () {
        Mail::fake();

        app(EmailChannel::class)->send($this->notification, $this->recipient);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) {
            return $mail->unsubscribeUserId === (string) $this->user->id
                && $mail->unsubscribeType === (string) $this->notification->type;
        });
    });

    it('NotificationMail emits a signed List-Unsubscribe URL + One-Click marker', function () {
        $mail = new NotificationMail('Subject', 'Body', (string) $this->user->id, 'recipe.approved');
        $headers = $mail->headers();
        $text = $headers->text;

        expect($text)->toHaveKey('List-Unsubscribe')
            ->and($text)->toHaveKey('List-Unsubscribe-Post')
            ->and($text['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
            ->and($text['List-Unsubscribe'])->toStartWith('<')
            ->and($text['List-Unsubscribe'])->toEndWith('>')
            // Signature param is appended by URL::signedRoute — its presence
            // is what makes the link tamper-proof.
            ->and($text['List-Unsubscribe'])->toContain('signature=');
    });

    it('NotificationMail emits no unsubscribe header when user/type missing', function () {
        $mail = new NotificationMail('Subject', 'Body');
        expect($mail->headers()->text)->toBe([]);
    });

    it('skips when recipient has no email address', function () {
        $this->user->update(['email' => '']);

        $result = app(EmailChannel::class)->send($this->notification, $this->recipient->fresh());

        expect($result->status)->toBe(NotificationDeliveryStatusEnum::Skipped);
    });

    it('returns failed when Mail::send throws', function () {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

        $result = app(EmailChannel::class)->send($this->notification, $this->recipient);

        expect($result->status)->toBe(NotificationDeliveryStatusEnum::Failed);
        expect($result->error)->toBe('smtp down');
    });
});

describe('PushChannel', function () {
    it('logs a breadcrumb and returns status=skipped without external call', function () {
        Log::spy();

        $result = app(PushChannel::class)->send($this->notification, $this->recipient);

        expect($result->status)->toBe(NotificationDeliveryStatusEnum::Skipped);
        Log::shouldHaveReceived('info')->with('notification.push.stub', Mockery::on(
            fn ($ctx) => $ctx['notification_id'] === $this->notification->id,
        ));
    });
});
