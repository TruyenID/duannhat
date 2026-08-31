<?php

namespace App\Services\Notification;

use App\Omnify\Enums\NotificationDeliveryStatusEnum;

/**
 * Plan-023 M4 T4.2 — value object carrying one provider-normalised
 * email event from a webhook into ApplyEmailEventJob.
 *
 * Created by per-driver MailWebhookContract::parseEvents. Consumed by
 * the queued ApplyEmailEventJob which updates NotificationDelivery
 * status + upserts NotificationEmailSuppression. The provider-specific
 * raw payload is preserved in `raw` so on-call can drill into a
 * specific webhook later without re-fetching it from the provider.
 */
final readonly class EmailEvent
{
    /**
     * @param  string  $messageId  Provider tracking id; matches notification_deliveries.provider_ref
     * @param  string  $recipientEmail  Address that bounced / complained / delivered
     * @param  NotificationDeliveryStatusEnum  $status  Mapped status enum
     * @param  string|null  $reason  Provider-supplied detail (bounce description, complaint type)
     * @param  array<string, mixed>  $raw  Full provider payload for audit
     */
    public function __construct(
        public string $messageId,
        public string $recipientEmail,
        public NotificationDeliveryStatusEnum $status,
        public ?string $reason,
        public \DateTimeImmutable $occurredAt,
        public array $raw,
    ) {}
}
