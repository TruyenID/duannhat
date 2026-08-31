<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Omnify\Enums\NotificationChannelEnum;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\NotificationChannelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a single (recipient × channel) notification delivery (plan-012
 * T3.7). Dispatched from `NotificationService::dispatch` after the parent
 * transaction commits.
 *
 * Retry policy: 3 attempts, exponential backoff [10, 60, 300]s. On final
 * failure the NotificationDelivery row is marked `status=failed` with the
 * exception message on `error`, and the failure is logged. Success or
 * `skipped` writes `sent_at` / `failed_at` / status immediately.
 *
 * Per-channel queue naming (`notifications-<channel>`) keeps SMTP retries
 * from blocking WebSocket fan-out.
 */
class NotificationChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Per-delivery claim lease, in seconds. Guards against two workers
     * processing the same delivery row concurrently — which would double-send
     * the email/realtime/push (issue #173 part D).
     *
     * `ShouldBeUnique` used to provide this dedup but was removed (predis cache
     * lock issues on the sync queue). It is replaced here by an atomic DB claim
     * (see `claim()`): on entry a worker compare-and-swaps the delivery row,
     * stamping `last_attempted_at`; a concurrent duplicate that arrives within
     * the lease loses the CAS and early-returns without sending. This is
     * queue-driver-agnostic (works on sync, database and redis).
     *
     * The lease MUST be shorter than the smallest retry backoff (10 s, see
     * `backoff()`) so a legitimate retry can always re-claim the row after its
     * backoff elapses, while a near-simultaneous duplicate cannot.
     */
    public const CLAIM_LEASE_SECONDS = 9;

    /**
     * Exponential backoff in seconds — plan-012 T3.7 spec.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function __construct(
        public readonly string $deliveryId,
    ) {
        $this->onQueue($this->queue());
    }

    public function queue(): string
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        $channelValue = $delivery?->channel instanceof NotificationChannelEnum
            ? $delivery->channel->value
            : ((string) ($delivery?->channel ?? 'in_app'));

        return 'notifications-'.$channelValue;
    }

    public function handle(NotificationChannelResolver $resolver): void
    {
        /** @var NotificationDelivery|null $delivery */
        $delivery = NotificationDelivery::query()
            ->with(['notificationRecipient.notification.template', 'notificationRecipient.recipient'])
            ->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        // Atomic per-delivery claim (replaces the removed ShouldBeUnique lock).
        // Exactly one worker wins the row for this attempt; a concurrent
        // duplicate — e.g. the orphan-recovery path re-queuing a still-pending
        // delivery — loses the claim and returns without sending. Without this,
        // the same delivery could be double-sent.
        if (! $this->claim($delivery)) {
            return;
        }

        $recipient = $delivery->notificationRecipient;
        if ($recipient === null || $recipient->notification === null) {
            $this->markFailed($delivery, 'parent recipient or notification missing');

            return;
        }

        $channelEnum = $delivery->channel instanceof NotificationChannelEnum
            ? $delivery->channel
            : NotificationChannelEnum::from((string) $delivery->channel);

        try {
            $impl = $resolver->for($channelEnum);
            $result = $impl->send($recipient->notification, $recipient);
        } catch (Throwable $e) {
            $this->markFailedOrRetry($delivery, $e->getMessage());

            throw $e;
        }

        if ($result->status === NotificationDeliveryStatusEnum::Sent) {
            $delivery->status = NotificationDeliveryStatusEnum::Sent;
            $delivery->sent_at = now();
            $delivery->error = null;
            $delivery->provider_ref = $result->providerRef;
            $delivery->save();

            return;
        }

        if ($result->status === NotificationDeliveryStatusEnum::Skipped) {
            $delivery->status = NotificationDeliveryStatusEnum::Skipped;
            $delivery->error = $result->error;
            $delivery->save();

            return;
        }

        if ($result->status === NotificationDeliveryStatusEnum::Failed) {
            $this->markFailedOrRetry($delivery, (string) $result->error);
            // Throw so the queue worker retries under the backoff schedule.
            throw new \RuntimeException('channel send failed: '.$result->error);
        }
    }

    /**
     * Atomically claim the delivery row for this attempt.
     *
     * Compare-and-swap: increment `attempts` + stamp `last_attempted_at` in a
     * single UPDATE, but only when the row is still `pending` AND its lease has
     * expired (never attempted, or last attempted longer ago than the lease).
     * The DB guarantees at most one concurrent worker's UPDATE affects the row,
     * so the loser sees `0` affected rows and bails. Legitimate retries re-claim
     * because their backoff (≥ 10 s) outlasts the lease (9 s).
     *
     * Returns true when this worker won the claim and should proceed to send.
     */
    private function claim(NotificationDelivery $delivery): bool
    {
        $now = now();
        $leaseCutoff = $now->copy()->subSeconds(self::CLAIM_LEASE_SECONDS);

        $won = NotificationDelivery::query()
            ->where('id', $delivery->id)
            ->where('status', NotificationDeliveryStatusEnum::Pending->value)
            ->where(function ($query) use ($leaseCutoff) {
                $query->whereNull('last_attempted_at')
                    ->orWhere('last_attempted_at', '<=', $leaseCutoff);
            })
            ->update([
                'attempts' => DB::raw('COALESCE(attempts, 0) + 1'),
                'last_attempted_at' => $now,
                'updated_at' => $now,
            ]);

        if ($won === 0) {
            return false;
        }

        // Sync the in-memory model with the values the atomic UPDATE persisted
        // so later ->save() calls in the send-result branches stay consistent.
        $delivery->refresh();

        return true;
    }

    public function failed(?Throwable $e): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        if ($delivery === null) {
            return;
        }
        $this->markFailed($delivery, $e?->getMessage() ?? 'retries exhausted');
    }

    private function markFailedOrRetry(NotificationDelivery $delivery, string $error): void
    {
        $delivery->error = $error;
        // Let the queue worker decide if this was the last attempt — only
        // the `failed()` hook writes the terminal failed status.
        $delivery->save();
    }

    private function markFailed(NotificationDelivery $delivery, string $error): void
    {
        $delivery->status = NotificationDeliveryStatusEnum::Failed;
        $delivery->failed_at = now();
        $delivery->error = $error;
        $delivery->save();

        Log::warning('notification.delivery.failed', [
            'delivery_id' => $delivery->id,
            'channel' => $delivery->channel?->value ?? null,
            'error' => $error,
        ]);
    }
}
