<?php

namespace App\Events\Notification;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan-023 M8 T8.2 — strict-typed payload for custom (non-Eloquent)
 * notification triggers. Every caller of Event::dispatch('custom.*')
 * MUST wrap the payload in this value object.
 *
 * shape: {subject: Model, changes: [], context: []}
 * - subject  The primary model context (e.g. Device for device.offline).
 * - changes  Optional model field-level change map (same shape as Eloquent bridge).
 * - context  Custom payload (e.g. minutes_offline, hours_remaining).
 */
final readonly class CustomNotificationEvent
{
    public function __construct(
        public readonly Model $subject,
        public readonly array $changes = [],
        public readonly array $context = [],
    ) {}
}
