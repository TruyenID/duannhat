<?php

namespace App\Contracts\Notification;

use App\Exceptions\WebhookVerificationException;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\EmailEvent;
use Illuminate\Http\Request;

/**
 * Plan-023 M4 T4.2 — provider-agnostic mail webhook contract.
 *
 * Implementations live under `App\Services\Notification\Webhooks\*`
 * and are registered with `MailWebhookManager` by string driver name
 * (config `notifications.mail_webhook_driver`, env `MAIL_WEBHOOK_DRIVER`).
 *
 * Postmark ships as the default driver in plan-023 (Decision 20). SES /
 * Mailgun / SendGrid become additive — implement this contract, register
 * the driver, point the env var. No schema change, no API change.
 *
 * Signature verification + replay-window enforcement happen inside
 * `verifySignature` rather than middleware so the per-provider hashing
 * scheme (HMAC-SHA1 for Postmark, SNS signature for SES, …) stays
 * encapsulated.
 */
interface MailWebhookContract
{
    /**
     * Reject the inbound webhook if the signature is invalid or the
     * payload is older than the provider's anti-replay window.
     *
     * @throws WebhookVerificationException on any mismatch
     */
    public function verifySignature(Request $request): void;

    /**
     * Translate the provider-specific payload into our canonical event
     * list. A single webhook can carry one event (Postmark) or many
     * (SES SNS batching), so the return type is always an array.
     *
     * @return array<int, EmailEvent>
     */
    public function parseEvents(Request $request): array;

    /**
     * Map a provider event-type string to our internal delivery status
     * enum. Throws when the event is unknown — the webhook controller
     * catches that and logs it without forwarding (the alternative is
     * silently inventing a state, which is worse).
     *
     * @throws \InvalidArgumentException on unknown event type
     */
    public function mapToDeliveryStatus(string $providerEvent): NotificationDeliveryStatusEnum;
}
