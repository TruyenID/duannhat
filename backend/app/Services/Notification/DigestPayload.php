<?php

namespace App\Services\Notification;

use App\Contracts\Notifiable;
use App\Models\NotificationRecipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Plan-023 M5 T5.5 — value object the DigestBuilderService hands off
 * to the Mailable. Carries the bucket counts so the email can render
 * "12 stock alerts, 3 order updates" without re-querying.
 */
final readonly class DigestPayload
{
    /**
     * @param  array<string, int>  $countsByType
     * @param  array<string, int>  $countsByPriority
     * @param  Collection<int, NotificationRecipient>  $sample
     */
    public function __construct(
        public Notifiable $recipient,
        public Carbon $windowStart,
        public Carbon $windowEnd,
        public int $totalCount,
        public array $countsByType,
        public array $countsByPriority,
        public Collection $sample,
    ) {}
}
