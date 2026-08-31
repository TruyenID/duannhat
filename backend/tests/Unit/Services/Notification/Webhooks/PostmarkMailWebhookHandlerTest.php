<?php

/**
 * Plan-023 M4 T4.3 — PostmarkMailWebhookHandler unit coverage.
 *
 * Scenarios:
 *   M4-2: valid signature → parseEvents returns one EmailEvent
 *   M4-3: invalid signature → WebhookVerificationException
 *   M4-4: replay window exceeded (> 5 min) → reject
 *   M4-5: unknown RecordType → InvalidArgumentException
 *   - missing secret → reject
 *   - missing signature header → reject
 *   - status mapping covers Delivery / Bounce / SpamComplaint / SubscriptionChange
 */

use App\Exceptions\WebhookVerificationException;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\Webhooks\PostmarkMailWebhookHandler;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['services.postmark.webhook_secret' => 'test-secret']);
    $this->handler = new PostmarkMailWebhookHandler;
});

function postmarkRequest(array $body, ?string $signature = null): Request
{
    $json = json_encode($body, JSON_THROW_ON_ERROR);
    $sig = $signature ?? base64_encode(hash_hmac('sha1', $json, 'test-secret', true));

    $request = Request::create('/api/v1/webhooks/mail/postmark', 'POST', server: [
        'HTTP_X_POSTMARK_SIGNATURE' => $sig,
        'CONTENT_TYPE' => 'application/json',
    ], content: $json);
    $request->headers->set('Content-Type', 'application/json');

    return $request;
}

it('M4-2: valid signature + Delivery event parses cleanly', function () {
    $request = postmarkRequest([
        'RecordType' => 'Delivery',
        'MessageID' => 'msg-123',
        'Recipient' => 'User@Example.com',
        'DeliveredAt' => now()->subSeconds(10)->toIso8601String(),
        'Details' => 'smtp;250 OK',
    ]);

    $this->handler->verifySignature($request);
    [$event] = $this->handler->parseEvents($request);

    expect($event->messageId)->toBe('msg-123')
        ->and($event->recipientEmail)->toBe('user@example.com')  // lowercased
        ->and($event->status)->toBe(NotificationDeliveryStatusEnum::Delivered)
        ->and($event->reason)->toBe('smtp;250 OK');
});

it('M4-3: signature mismatch raises WebhookVerificationException', function () {
    $request = postmarkRequest(
        ['RecordType' => 'Delivery', 'MessageID' => 'msg-x', 'DeliveredAt' => now()->toIso8601String()],
        signature: 'bogus',
    );

    expect(fn () => $this->handler->verifySignature($request))
        ->toThrow(WebhookVerificationException::class);
});

it('M4-4: replay > 5 min raises WebhookVerificationException', function () {
    $request = postmarkRequest([
        'RecordType' => 'Delivery',
        'MessageID' => 'msg-stale',
        'DeliveredAt' => now()->subMinutes(10)->toIso8601String(),
    ]);

    expect(fn () => $this->handler->verifySignature($request))
        ->toThrow(WebhookVerificationException::class, 'replay_window_exceeded');
});

it('M4-5: unknown RecordType throws InvalidArgumentException', function () {
    expect(fn () => $this->handler->mapToDeliveryStatus('ChatMessage'))
        ->toThrow(InvalidArgumentException::class, 'Unknown Postmark RecordType');
});

it('rejects when POSTMARK_WEBHOOK_SECRET is empty', function () {
    config(['services.postmark.webhook_secret' => '']);
    $request = postmarkRequest(['RecordType' => 'Delivery', 'MessageID' => 'x', 'DeliveredAt' => now()->toIso8601String()]);

    expect(fn () => $this->handler->verifySignature($request))
        ->toThrow(WebhookVerificationException::class, 'POSTMARK_WEBHOOK_SECRET is not configured');
});

it('rejects when signature header is missing', function () {
    $request = Request::create('/', 'POST', content: '{"RecordType":"Delivery"}');

    expect(fn () => $this->handler->verifySignature($request))
        ->toThrow(WebhookVerificationException::class, 'signature_missing');
});

it('maps every supported event type', function (string $event, NotificationDeliveryStatusEnum $expected) {
    expect($this->handler->mapToDeliveryStatus($event))->toBe($expected);
})->with([
    ['Delivery', NotificationDeliveryStatusEnum::Delivered],
    ['Bounce', NotificationDeliveryStatusEnum::Bounced],
    ['HardBounce', NotificationDeliveryStatusEnum::Bounced],
    ['SpamComplaint', NotificationDeliveryStatusEnum::Complained],
    ['SubscriptionChange', NotificationDeliveryStatusEnum::Suppressed],
]);
