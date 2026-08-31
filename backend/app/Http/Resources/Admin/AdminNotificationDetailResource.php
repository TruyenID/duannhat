<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detail shape for `GET /hq/{brand}/notifications/{id}`. Includes the full
 * recipients fan-out with each recipient's morph-resolved model + per-row
 * seen/read/dismissed state.
 */
class AdminNotificationDetailResource extends JsonResource
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
            'idempotency_key' => $n->idempotency_key,
            'aggregation_key' => $n->aggregation_key,
            'is_dispatched' => (bool) $n->is_dispatched,
            'scheduled_for' => $n->scheduled_for?->toIso8601String(),
            'audience_id' => $n->audience_id,
            'created_at' => $n->created_at?->toIso8601String(),
            'recipients' => collect($n->recipients)->map(fn ($row) => [
                'id' => $row->id,
                'recipient' => $this->formatMorph(
                    $row->recipient_type,
                    $row->recipient_id,
                    $row->recipient,
                ),
                'seen_at' => $row->seen_at?->toIso8601String(),
                'read_at' => $row->read_at?->toIso8601String(),
                'dismissed_at' => $row->dismissed_at?->toIso8601String(),
                'resolved_via' => $row->resolved_via,
            ])->values()->all(),
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
