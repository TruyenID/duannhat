<?php

/**
 * Notification Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Models\NotificationRecipient;
use App\Omnify\Modules\Notification\Resources\NotificationResourceBase;
use App\Services\Notification\NotificationService;
use App\Services\Notification\TemplateRenderer;
use Illuminate\Http\Request;

/**
 * Own-inbox serialization for a Notification. The outer paginator row is a
 * `NotificationRecipient` (per-user state); this resource renders the inner
 * Notification content + the recipient's own seen/read/dismissed columns.
 *
 * The caller (NotificationController) wraps this via a custom mapper that
 * feeds Notification + the recipient row explicitly — see
 * `NotificationController::formatRecipientRow`.
 */
class NotificationResource extends NotificationResourceBase
{
    /** @var NotificationRecipient|null Explicit recipient row for the current caller. */
    public ?NotificationRecipient $recipientRow = null;

    public static function forRecipientRow(NotificationRecipient $row): self
    {
        $resource = new self($row->notification);
        $resource->recipientRow = $row;

        return $resource;
    }

    public function toArray(Request $request): array
    {
        $n = $this->resource;

        [$title, $body] = $this->renderedContent($request);

        return [
            'id' => $n->id,
            'type' => $n->type,
            'template_key' => $n->template_key,
            'title' => $title,
            'body' => $body,
            'params' => $n->params ?? [],
            'priority' => $n->priority?->value ?? 'normal',
            'actor' => $this->formatMorph($n->actor_type, $n->actor_id, $n->actor),
            'subject' => $this->formatMorph($n->subject_type, $n->subject_id, $n->subject),
            'aggregation_key' => $n->aggregation_key,
            'created_at' => $n->created_at?->toIso8601String(),
            'recipient' => $this->recipientRow === null ? null : [
                'seen_at' => $this->recipientRow->seen_at?->toIso8601String(),
                'read_at' => $this->recipientRow->read_at?->toIso8601String(),
                'dismissed_at' => $this->recipientRow->dismissed_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Resolve the notification's title/body for the caller's locale.
     *
     * Resolution order (issue #179 part B):
     *   1. `params.__snapshot[<locale>]` — captured at dispatch time, gives
     *      stable forensic-grade rendering even if the template was edited
     *      or deleted later. New notifications always have this.
     *   2. `params.__snapshot[<fallback>]` — same chain (locale → ja → en)
     *      but inside the snapshot.
     *   3. Live template render — backwards-compatible path for older
     *      notifications dispatched before the snapshot rolled out.
     *   4. Empty body + literal template_key as title — last-resort when no
     *      template can be resolved at all.
     *
     * Decision 15 (plan-008) called for "no snapshot — inbox reflects
     * current template". That stays the default for ad-hoc inline templates
     * (no persisted row → no snapshot). For persisted templates we now
     * snapshot at dispatch — see NotificationService::snapshotTemplateContent.
     *
     * @return array{0: string, 1: string}
     */
    private function renderedContent(Request $request): array
    {
        $n = $this->resource;
        $locale = $this->resolveLocale($request);

        $snapshot = $this->snapshotFor($locale);
        if ($snapshot !== null) {
            return [$snapshot['title'] ?? '', $snapshot['body'] ?? ''];
        }

        $template = $n->relationLoaded('template') ? $n->getRelation('template') : $n->template()->first();
        if ($template === null) {
            return [(string) ($n->template_key ?? $n->type ?? ''), ''];
        }

        $rendered = app(TemplateRenderer::class)->render($template, (array) ($n->params ?? []), $locale);

        return [$rendered['title'], $rendered['body']];
    }

    /**
     * Look up `params.__snapshot[<locale>]` with the same fallback chain the
     * live renderer uses (locale → ja → en). Returns null when no snapshot
     * was captured for this notification (legacy rows or inline templates).
     *
     * @return array{title?: string, body?: string}|null
     */
    private function snapshotFor(string $locale): ?array
    {
        $n = $this->resource;
        $params = (array) ($n->params ?? []);
        $snapshot = $params[NotificationService::SNAPSHOT_KEY] ?? null;
        if (! is_array($snapshot) || $snapshot === []) {
            return null;
        }

        if (isset($snapshot[$locale]) && is_array($snapshot[$locale])) {
            return $snapshot[$locale];
        }

        foreach (TemplateRenderer::FALLBACK_LOCALES as $fallback) {
            if ($fallback !== $locale && isset($snapshot[$fallback]) && is_array($snapshot[$fallback])) {
                return $snapshot[$fallback];
            }
        }

        return null;
    }

    private function resolveLocale(Request $request): string
    {
        $user = $request->user();
        if ($user !== null && ! empty($user->locale)) {
            return (string) $user->locale;
        }

        $accept = (string) $request->header('Accept-Language', '');
        if ($accept !== '') {
            $first = strtolower(trim(explode(',', $accept)[0]));
            // Strip region: `ja-JP` → `ja`
            $lang = explode('-', $first)[0];
            if (in_array($lang, ['ja', 'en', 'vi'], true)) {
                return $lang;
            }
        }

        return 'ja';
    }

    /**
     * Minimal morph projection — `{type, id, display_name?}`. Returns null
     * when the morph pair is (null, null) for system events / rows without a
     * subject.
     *
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
