<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row shape for `GET /hq/{brand}/notifications`. Admin is NOT a recipient,
 * so the row carries a `recipients_summary` aggregate instead of a
 * per-recipient `recipient` block.
 */
class AdminNotificationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $n = $this->resource;

        return [
            'id' => $n->id,
            'type' => $n->type,
            'template_key' => $n->template_key,
            'params' => $n->params ?? [],
            'priority' => $n->priority?->value ?? 'normal',
            'actor' => $this->formatMorph($n->actor_type, $n->actor_id, $n->actor),
            'subject' => $this->formatMorph($n->subject_type, $n->subject_id, $n->subject),
            'organization' => $n->organization ? [
                'id' => $n->organization->id,
                'name' => $n->organization->name ?? null,
            ] : null,
            'aggregation_key' => $n->aggregation_key,
            'is_dispatched' => (bool) $n->is_dispatched,
            'scheduled_for' => $n->scheduled_for?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
            'recipients_summary' => [
                'total' => (int) ($n->recipients_total ?? 0),
                'seen' => (int) ($n->recipients_seen ?? 0),
                'read' => (int) ($n->recipients_read ?? 0),
                'dismissed' => (int) ($n->recipients_dismissed ?? 0),
            ],
        ];
    }

    /**
     * @return array{type: string, id: string, display_name: ?string}|null
     */
    private function formatMorph(?string $morphType, ?string $morphId, $model): ?array
    {
        if ($morphType === null || $morphId === null) {
            return null;
        }

        return [
            'type' => $morphType,
            'id' => $morphId,
            'display_name' => $model?->display_name ?? $model?->name ?? null,
        ];
    }
}
