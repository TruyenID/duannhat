<?php

namespace App\Http\Resources;

use App\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * plan-052 M2 / T2.2 — one row of the Print jobs screen.
 *
 * The field that carries the most weight here is `confidence` (P-33). A cheap
 * ESC/POS machine on a raw socket can only ever report `sent_only` — "the
 * bytes left, and this machine cannot tell us more". Showing that identically
 * to a CloudPRNT `confirmed` would teach ops to trust a number nobody
 * measured, so the API states it plainly and adds `confidence_label` so a UI
 * cannot accidentally render the two the same by forgetting a mapping.
 *
 * `event_at` is the job's REAL time (P-07): when the paper came out, falling
 * back to when the row was written. Never the sync time — an offline evening
 * must not collapse onto the next morning (#1091).
 *
 * @mixin PrintJob
 */
class PrintJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $eventAt = $this->printed_reported_at ?? $this->created_at;

        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'printer_id' => $this->printer_id,
            'printer_name' => $this->whenLoaded('printer', fn () => $this->printer?->name),
            'transport' => $this->transport?->value,
            'kind' => $this->kind?->value,
            'is_money_document' => (bool) $this->kind?->isMoneyDocument(),
            'status' => $this->status?->value,
            'is_terminal' => (bool) $this->status?->isTerminal(),

            // P-33 — never collapse these two into one "printed".
            'confidence' => $this->confidence?->value,
            'confidence_label' => $this->confidenceLabel(),

            'order_id' => $this->order_id,
            'payment_id' => $this->payment_id,
            'reprint_no' => $this->reprint_no,
            'reprint_reason' => $this->reprint_reason,
            // §4 — a reprint with no reason is never refused, so the screen has
            // to be able to SHOW it. This is the whole of what replaced the 422.
            'warned_without_reason' => $this->warnedWithoutReason(),
            'reprint_marker_printed' => $this->reprintMarkerPrinted(),
            'requested_via' => $this->requested_via,
            'requested_by_id' => $this->requested_by_id,

            'attempts' => $this->attempts,
            'last_error' => $this->last_error,

            'event_at' => $eventAt?->toISOString(),
            'printed_reported_at' => $this->printed_reported_at?->toISOString(),
            'acked_at' => $this->acked_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            'resolution' => $this->whenLoaded('resolution', fn () => $this->resolution === null ? null : [
                'resolution' => $this->resolution->resolution?->value,
                'reason' => $this->resolution->reason,
                'resolved_by_id' => $this->resolution->resolved_by_id,
                'resolved_at' => $this->resolution->resolved_at?->toISOString(),
            ]),
        ];
    }

    /**
     * A phrase a screen can print as-is. `printed` alone is a half-truth on a
     * machine that cannot answer back; naming the difference here means every
     * client gets it right without re-deriving the rule.
     */
    private function confidenceLabel(): ?string
    {
        if ($this->status === null) {
            return null;
        }

        return match (true) {
            $this->status->value !== 'printed' => $this->status->value,
            $this->confidence?->value === 'confirmed' => 'printed_confirmed',
            default => 'printed_sent_only',
        };
    }
}
