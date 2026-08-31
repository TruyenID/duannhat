<?php

/**
 * OrderPayment Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\OrderPayment\Resources\OrderPaymentResourceBase;
use Illuminate\Http\Request;

/**
 * OrderPaymentResource — wire contract for POS + reconciliation consumers.
 *
 * Fields curated for plan-007 POS + plan-008 split tender:
 *   - IDs: payment FK fields kept so clients don't need to refetch
 *     (payment_method_id, customer_order_id, refund_of_id, received_by_id)
 *   - Relation: payment_method loaded as a compact {id,code,name}
 *     summary; snake_case key matches POS convention
 *   - Money fields: amount, tip_amount, tendered_amount, change_amount
 *   - Lifecycle: status, paid_at, created_at
 *
 * Intentionally omitted (not needed on the wire):
 *   - branch_id / brand_id / organization_id — enforced server-side by
 *     policies; clients scope by order
 *   - updated_at / deleted_at — payments are append-only in practice
 *
 * `metadata` is on the wire so POS can derive per-item paid units from
 * `metadata.item_allocations[]` when reopening the split-bill modal on a
 * partially-paid order (would otherwise show ghost allocations for items
 * a previous succeeded payment already claimed).
 */
class OrderPaymentResource extends OrderPaymentResourceBase
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_code' => $this->payment_code,
            'customer_order_id' => $this->customer_order_id,
            'payment_method_id' => $this->payment_method_id,
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => [
                'id' => $this->paymentMethod->id,
                'code' => $this->paymentMethod->code,
                'name' => $this->paymentMethod->name,
            ]),
            'amount' => $this->amount,
            'tip_amount' => $this->tip_amount,
            'status' => $this->status instanceof \BackedEnum
                ? $this->status->value
                : $this->status,
            'tendered_amount' => $this->tendered_amount,
            'change_amount' => $this->change_amount,
            'reference_no' => $this->reference_no,
            // #1156 — brand-level tender attribution (till_tender_types key).
            'tender_key' => $this->tender_key,
            'paid_at' => $this->paid_at?->toISOString(),
            'note' => $this->note,
            'refund_of_id' => $this->refund_of_id,
            'received_by_id' => $this->received_by_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
