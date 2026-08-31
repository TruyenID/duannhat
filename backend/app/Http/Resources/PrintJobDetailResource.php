<?php

namespace App\Http\Resources;

use App\Models\PrintJob;
use App\Services\Printing\PrintJobRegistry;
use Illuminate\Http\Request;

/**
 * plan-052 M2 / T2.2 — the detail view: the list row plus everything a person
 * needs to decide what to do about it.
 *
 * On "attempt history": there is no per-attempt table, and inventing one here
 * would be a lie. For a `ws_lan` job the attempts happened on the workstation,
 * which owns that queue (DESIGN §1b) and reports a COUNT plus the last error
 * when it journals the result. So `delivery` states exactly that: how many
 * tries, how many the matrix allows, whether another try may happen without a
 * human — and, for a money document, the answer to that last question is
 * always no (P-05 / RISKS PR1).
 *
 * `timeline` is derived from the timestamps the row already carries. It is a
 * reading aid, not a second source of truth.
 *
 * @mixin PrintJob
 */
class PrintJobDetailResource extends PrintJobResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $registry = app(PrintJobRegistry::class);
        $policy = $registry->policyFor($this->kind);

        return array_merge(parent::toArray($request), [
            'payload' => $this->payload,

            'delivery' => [
                'attempts' => $this->attempts,
                'max_attempts' => $policy['max_attempts'],
                // The registry's answer, not a guess: money documents are
                // false from every state, including needs_attention.
                'auto_retry_allowed' => $registry->shouldAutoRetry($this->kind, $this->status, (int) $this->attempts),
                'auto_retry_allowed_for_kind' => $policy['auto_retry'],
                'ttl_seconds' => $policy['ttl_seconds'],
                'last_error' => $this->last_error,
                // Whoever owns the queue is who may act (DESIGN §1b). A
                // ws_lan row says "workstation" — Cloud will not retry it and
                // no button here should pretend otherwise.
                'queue_owner' => $this->transport?->isJournalMode() ? 'workstation' : 'cloud',
            ],

            'timeline' => array_values(array_filter([
                $this->created_at === null ? null : ['event' => 'recorded', 'at' => $this->created_at->toISOString()],
                $this->acked_at === null ? null : ['event' => 'acked', 'at' => $this->acked_at->toISOString()],
                $this->printed_reported_at === null ? null : ['event' => 'printed_reported', 'at' => $this->printed_reported_at->toISOString()],
                $this->expires_at === null ? null : ['event' => 'expires', 'at' => $this->expires_at->toISOString()],
                $this->relationLoaded('resolution') && $this->resolution !== null
                    ? ['event' => 'resolved', 'at' => $this->resolution->resolved_at?->toISOString()]
                    : null,
            ])),
        ]);
    }
}
