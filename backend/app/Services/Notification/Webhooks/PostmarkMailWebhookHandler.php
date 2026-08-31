<?php

namespace App\Services\Notification\Webhooks;

use App\Contracts\Notification\MailWebhookContract;
use App\Exceptions\WebhookVerificationException;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\EmailEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Plan-023 M4 T4.3 — Postmark mail webhook driver.
 *
 * Postmark posts a JSON body per event (Delivery / Bounce / HardBounce /
 * SpamComplaint / SubscriptionChange / …) signed with a server-stored
 * basic-auth user. We use the HMAC variant for stronger guarantees:
 *   X-Postmark-Signature: base64(HMAC-SHA1(raw-body, SHARED_SECRET))
 *
 * Replay window: events older than 5 minutes (per the per-event
 * timestamp field) are rejected — Postmark retries faster than that on
 * 5xx so the only legitimate source of a > 5-min-old event is a
 * replay attack.
 *
 * Unknown event types raise an InvalidArgumentException; the webhook
 * controller catches that and returns 202 with `{accepted: 0}` so
 * Postmark stops retrying without us pretending we processed a state
 * we don't understand.
 */
final class PostmarkMailWebhookHandler implements MailWebhookContract
{
    private const REPLAY_WINDOW_MINUTES = 5;

    public function verifySignature(Request $request): void
    {
        $secret = (string) config('services.postmark.webhook_secret', '');
        if ($secret === '') {
            throw new WebhookVerificationException('secret_missing', 'POSTMARK_WEBHOOK_SECRET is not configured.');
        }

        $signature = (string) $request->header('X-Postmark-Signature', '');
        if ($signature === '') {
            throw new WebhookVerificationException('signature_missing');
        }

        $expected = base64_encode(hash_hmac('sha1', $request->getContent(), $secret, true));
        if (! hash_equals($expected, $signature)) {
            throw new WebhookVerificationException('signature_mismatch');
        }

        $timestamp = $this->extractTimestamp($request);
        if ($timestamp !== null && Carbon::parse($timestamp)->diffInMinutes(now()) > self::REPLAY_WINDOW_MINUTES) {
            throw new WebhookVerificationException('replay_window_exceeded');
        }
    }

    public function parseEvents(Request $request): array
    {
        $recordType = (string) $request->json('RecordType', '');
        if ($recordType === '') {
            throw new \InvalidArgumentException('Postmark webhook missing RecordType field.');
        }

        $status = $this->mapToDeliveryStatus($recordType);

        return [
            new EmailEvent(
                messageId: (string) $request->json('MessageID', ''),
                recipientEmail: strtolower((string) ($request->json('Recipient') ?? $request->json('Email') ?? '')),
                status: $status,
                reason: $request->json('Details') ?? $request->json('Description') ?? null,
                occurredAt: new \DateTimeImmutable($this->extractTimestamp($request) ?? 'now'),
                raw: (array) $request->all(),
            ),
        ];
    }

    public function mapToDeliveryStatus(string $providerEvent): NotificationDeliveryStatusEnum
    {
        return match ($providerEvent) {
            'Delivery' => NotificationDeliveryStatusEnum::Delivered,
            'Bounce', 'HardBounce' => NotificationDeliveryStatusEnum::Bounced,
            'SpamComplaint' => NotificationDeliveryStatusEnum::Complained,
            'SubscriptionChange' => NotificationDeliveryStatusEnum::Suppressed,
            default => throw new \InvalidArgumentException("Unknown Postmark RecordType: {$providerEvent}"),
        };
    }

    /**
     * Pull a timestamp out of whichever per-event field Postmark uses
     * for the current RecordType. Returns null when no timestamp can be
     * located — we then skip replay-window enforcement rather than
     * reject legitimate events that don't carry a clock.
     */
    private function extractTimestamp(Request $request): ?string
    {
        return $request->json('DeliveredAt')
            ?? $request->json('BouncedAt')
            ?? $request->json('ReceivedAt')
            ?? $request->json('ChangedAt');
    }
}
