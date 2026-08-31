<?php

namespace App\Jobs\Notification;

use App\Models\NotificationDelivery;
use App\Models\NotificationEmailSuppression;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\EmailEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Plan-023 M4 T4.6 — apply a parsed mail-webhook event to our local
 * delivery + suppression state.
 *
 * The webhook controller dispatches one job per `EmailEvent` so the
 * webhook can return 202 in well under 100 ms and the actual DB work
 * happens async. Each job:
 *
 *   1. Finds the matching NotificationDelivery via `provider_ref =
 *      $event->messageId`. Missing → log warning + return (delivery
 *      could have been pruned, or the event belongs to mail sent
 *      outside our system).
 *   2. Updates the delivery's `status` to the mapped enum + stamps the
 *      relevant timestamp column (delivered_at / failed_at / etc).
 *   3. When status is Bounced / Complained / Suppressed → upserts a
 *      NotificationEmailSuppression row scoped to the recipient's
 *      organization so future EmailChannel::send calls short-circuit.
 *
 * Queue: `notifications-email-webhook` so a slow webhook backlog can't
 * starve the realtime / preference / digest queues.
 */
class ApplyEmailEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly EmailEvent $event)
    {
        $this->onQueue('notifications-email-webhook');
    }

    public function handle(): void
    {
        $delivery = NotificationDelivery::query()
            ->where('provider_ref', $this->event->messageId)
            ->first();

        if ($delivery === null) {
            Log::warning('mail-webhook.delivery-not-found', [
                'message_id' => $this->event->messageId,
                'status' => $this->event->status->value,
            ]);

            return;
        }

        $this->updateDeliveryStatus($delivery);
        $this->maybeRecordSuppression($delivery);
    }

    private function updateDeliveryStatus(NotificationDelivery $delivery): void
    {
        $occurredAt = Carbon::instance($this->event->occurredAt);

        $delivery->status = $this->event->status->value;

        match ($this->event->status) {
            NotificationDeliveryStatusEnum::Delivered => $delivery->delivered_at = $occurredAt,
            NotificationDeliveryStatusEnum::Bounced,
            NotificationDeliveryStatusEnum::Complained,
            NotificationDeliveryStatusEnum::Suppressed,
            NotificationDeliveryStatusEnum::Failed => $delivery->failed_at = $occurredAt,
            default => null,
        };

        if ($this->event->reason !== null && $this->event->reason !== '') {
            $delivery->error = $this->event->reason;
        }

        $delivery->save();
    }

    private function maybeRecordSuppression(NotificationDelivery $delivery): void
    {
        $reason = $this->reasonFor($this->event->status);
        if ($reason === null) {
            return;
        }

        $organizationId = $this->resolveOrganizationId($delivery);
        if ($organizationId === null) {
            Log::warning('mail-webhook.organization-not-found', [
                'delivery_id' => $delivery->id,
                'message_id' => $this->event->messageId,
            ]);

            return;
        }

        $email = strtolower(trim($this->event->recipientEmail));
        if ($email === '') {
            return;
        }

        NotificationEmailSuppression::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'email' => $email,
                'reason' => $reason,
            ],
            [
                'id' => (string) Str::uuid(),
                'source_provider' => $this->guessProvider(),
                'suppressed_at' => Carbon::instance($this->event->occurredAt),
                'un_suppressed_at' => null,
            ],
        );
    }

    private function reasonFor(NotificationDeliveryStatusEnum $status): ?string
    {
        return match ($status) {
            NotificationDeliveryStatusEnum::Bounced => 'hard_bounce',
            NotificationDeliveryStatusEnum::Complained => 'spam_complaint',
            NotificationDeliveryStatusEnum::Suppressed => 'subscription_change',
            default => null,
        };
    }

    private function resolveOrganizationId(NotificationDelivery $delivery): ?string
    {
        // Delivery → NotificationRecipient → Notification → organization_id
        $delivery->loadMissing('notificationRecipient.notification');

        return $delivery->notificationRecipient?->notification?->organization_id;
    }

    private function guessProvider(): string
    {
        $type = (string) ($this->event->raw['RecordType'] ?? '');

        return $type !== '' ? 'postmark' : 'unknown';
    }
}
