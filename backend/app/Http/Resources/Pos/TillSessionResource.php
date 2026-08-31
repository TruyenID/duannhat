<?php

namespace App\Http\Resources\Pos;

use App\Models\TillCashDenominationCount;
use App\Models\TillSession;
use App\Models\TillSettlementTenderDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 030 — TillSession serializer for /pos/till/sessions/* responses.
 *
 * Hand-rolled (not extending TillSessionResourceBase) so we can shape the
 * payload to match what pos-web actually consumes: include opening +
 * closing denomination counts as separate arrays, cash events, settlement
 * details, and computed totals — without pulling every base column.
 */
class TillSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TillSession $s */
        $s = $this->resource;

        return [
            'id' => $s->id,
            'session_code' => $s->session_code,
            'status' => $this->stringify($s->status),
            'business_date' => optional($s->business_date)->toDateString() ?? $s->business_date,
            'default_currency_code' => $s->default_currency_code,
            'opening_float_amount' => (float) $s->opening_float_amount,
            'expected_cash_amount' => $s->expected_cash_amount !== null ? (float) $s->expected_cash_amount : null,
            'counted_cash_amount' => $s->counted_cash_amount !== null ? (float) $s->counted_cash_amount : null,
            'closing_cash_adjustment_amount' => $s->closing_cash_adjustment_amount !== null ? (float) $s->closing_cash_adjustment_amount : null,
            'cash_variance_amount' => $s->cash_variance_amount !== null ? (float) $s->cash_variance_amount : null,
            'opening_note' => $s->opening_note,
            'closing_note' => $s->closing_note,
            'opened_by_id' => $s->opened_by_id,
            'closed_by_id' => $s->closed_by_id,
            'opener_name' => $s->opener_name,
            'opened_at' => optional($s->opened_at)?->toIso8601String(),
            'closed_at' => optional($s->closed_at)?->toIso8601String(),
            'abandoned_at' => optional($s->abandoned_at)?->toIso8601String(),
            'abandon_reason' => $s->abandon_reason,

            // Plan-032 — exposed for the admin-web "Ca treo" tab + audit UI.
            'force_abandoned' => (bool) $s->force_abandoned,
            'force_abandoned_by_id' => $s->force_abandoned_by_id,
            'force_abandon_reason_code' => $this->stringify($s->force_abandon_reason_code),
            'force_abandon_reason_detail' => $s->force_abandon_reason_detail,
            'expired_at' => optional($s->expired_at)?->toIso8601String(),
            'expire_reason' => $this->stringify($s->expire_reason),
            'expire_threshold_hours' => $s->expire_threshold_hours !== null ? (int) $s->expire_threshold_hours : null,

            // Plan-046 (P6-2) — chain fields for the "Ca N" badge + open/close
            // flow. This resource is a hand-rolled whitelist (it does NOT auto-
            // include model columns), so the chain fields only surface here. The
            // large settlement_snapshot stays off this payload — it is internal
            // and only summed by the chain-summary endpoint.
            'chain_id' => $s->chain_id,
            'chain_sequence' => (int) $s->chain_sequence,
            'settlement_kind' => $this->stringify($s->settlement_kind),

            'till_id' => $s->till_id,
            'branch_id' => $s->branch_id,
            'opening_counts' => $this->whenLoaded('openingCounts', function () use ($s) {
                return $s->openingCounts
                    ->map(fn (TillCashDenominationCount $c) => $this->serializeCount($c))
                    ->values()
                    ->all();
            }),
            'closing_counts' => $this->whenLoaded('closingCounts', function () use ($s) {
                return $s->closingCounts
                    ->map(fn (TillCashDenominationCount $c) => $this->serializeCount($c))
                    ->values()
                    ->all();
            }),
            'cash_events' => $this->whenLoaded('cashEvents', function () use ($s) {
                return $s->cashEvents->map(fn ($e) => [
                    'id' => $e->id,
                    'event_type' => $this->stringify($e->event_type),
                    'amount' => (float) $e->amount,
                    'currency_code' => $e->currency_code,
                    'reason' => $e->reason,
                    'reference_no' => $e->reference_no,
                    'performed_by_id' => $e->performed_by_id,
                    'occurred_at' => optional($e->occurred_at)?->toIso8601String(),
                ])->all();
            }),
            'settlement_details' => $this->whenLoaded('settlementDetails', function () use ($s) {
                return $s->settlementDetails->map(fn (TillSettlementTenderDetail $d) => [
                    'id' => $d->id,
                    'tender_key' => $d->tender_key,
                    'category' => $this->stringify($d->category),
                    'currency_code' => $d->currency_code,
                    'expected_amount' => $d->expected_amount !== null ? (float) $d->expected_amount : null,
                    'declared_gross_amount' => (float) $d->declared_gross_amount,
                    'declared_cancel_amount' => (float) $d->declared_cancel_amount,
                    'declared_amount' => (float) $d->declared_amount,
                    'terminal_batch_total' => $d->terminal_batch_total !== null ? (float) $d->terminal_batch_total : null,
                    'variance_amount' => $d->variance_amount !== null ? (float) $d->variance_amount : null,
                    'variance_reason' => $d->variance_reason,
                ])->all();
            }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCount(TillCashDenominationCount $c): array
    {
        return [
            'id' => $c->id,
            'denomination_id' => $c->denomination_id,
            'currency_code' => $c->currency_code,
            'denomination_value' => (float) $c->denomination_value,
            'denomination_kind' => $this->stringify($c->denomination_kind),
            'quantity' => (int) $c->quantity,
            'subtotal_amount' => (float) $c->subtotal_amount,
        ];
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
