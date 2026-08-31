<?php

namespace App\Services\Notification\Channels;

use App\Omnify\Enums\NotificationDeliveryStatusEnum;

/**
 * Value object returned by every `NotificationChannelContract::send()`.
 * The `NotificationChannelJob` maps this into the
 * `notification_deliveries` row update.
 */
final readonly class DeliveryResult
{
    public function __construct(
        public NotificationDeliveryStatusEnum $status,
        public ?string $error = null,
        public ?string $providerRef = null,
    ) {}

    public static function sent(?string $providerRef = null): self
    {
        return new self(NotificationDeliveryStatusEnum::Sent, null, $providerRef);
    }

    public static function skipped(?string $reason = null): self
    {
        return new self(NotificationDeliveryStatusEnum::Skipped, $reason);
    }

    public static function failed(string $error): self
    {
        return new self(NotificationDeliveryStatusEnum::Failed, $error);
    }
}
