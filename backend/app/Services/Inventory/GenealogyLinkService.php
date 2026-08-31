<?php

namespace App\Services\Inventory;

use App\Models\GenealogyLink;
use App\Models\MaterialLot;
use Illuminate\Support\Facades\DB;

/**
 * GenealogyLinkService
 *
 * Append-only writer for `genealogy_links` rows. Exposes ONLY `record*`
 * methods — no update / delete. Compensating writes happen via
 * recordManualAdjustment() rather than mutation, so the audit trail stays
 * permanent (FSMA-204 retention requirement).
 *
 * Read paths live in TraceService — this service is write-only.
 */
class GenealogyLinkService
{
    /**
     * Record a production edge: parent_lot was consumed to mint child_lot
     * inside a MaterialBatch::complete().
     */
    public function recordProductionConsumption(
        MaterialLot $parentLot,
        MaterialLot $childLot,
        float $qtyConsumed,
        ?string $unit,
        string $batchId,
        ?\DateTimeInterface $consumedAt = null,
    ): GenealogyLink {
        return $this->recordEdge(
            parentLotId: $parentLot->id,
            childLotId: $childLot->id,
            customerOrderId: null,
            qtyConsumed: $qtyConsumed,
            unit: $unit ?? $parentLot->unit,
            consumedAt: $consumedAt ?? now(),
            sourceEventType: 'material_batch',
            sourceEventId: $batchId,
        );
    }

    /**
     * Record a sales edge: parent_lot was consumed for a customer_order_id
     * inside StockTransactionService::completeTransaction (sub_type=sales).
     * No child lot — the consumption terminates at the customer.
     */
    public function recordSalesConsumption(
        MaterialLot $parentLot,
        string $customerOrderId,
        float $qtyConsumed,
        ?string $unit,
        string $transactionId,
        ?\DateTimeInterface $consumedAt = null,
    ): GenealogyLink {
        return $this->recordEdge(
            parentLotId: $parentLot->id,
            childLotId: null,
            customerOrderId: $customerOrderId,
            qtyConsumed: $qtyConsumed,
            unit: $unit ?? $parentLot->unit,
            consumedAt: $consumedAt ?? now(),
            sourceEventType: 'customer_order',
            sourceEventId: $transactionId,
        );
    }

    /**
     * Record a disposal edge: parent_lot was discarded (recall, expiry, etc.).
     * No child, no customer order. source_event_id points at a DisposalRecord.
     */
    public function recordDisposal(
        MaterialLot $parentLot,
        float $qtyConsumed,
        ?string $unit,
        string $disposalRecordId,
        ?\DateTimeInterface $consumedAt = null,
    ): GenealogyLink {
        return $this->recordEdge(
            parentLotId: $parentLot->id,
            childLotId: null,
            customerOrderId: null,
            qtyConsumed: $qtyConsumed,
            unit: $unit ?? $parentLot->unit,
            consumedAt: $consumedAt ?? now(),
            sourceEventType: 'disposal',
            sourceEventId: $disposalRecordId,
        );
    }

    /**
     * plan-018 audit fix (Finding 2) — record a substitution edge. When the
     * FEFO picker exhausts the primary material and falls back to a substitute
     * (per an opted-in MaterialSubstitutionRule), the substitute lot was
     * consumed in the primary's place. Emit an append-only edge with
     * `source_event_type='substitution'` so TraceService / recall can see the
     * swap. `source_event_id` is the stock transaction; the primary material it
     * stood in for rides in the edge's unit-agnostic metadata via the audit log
     * written alongside it.
     */
    public function recordSubstitution(
        MaterialLot $substituteLot,
        string $primaryMaterialId,
        float $qtyConsumed,
        ?string $unit,
        string $transactionId,
        ?\DateTimeInterface $consumedAt = null,
    ): GenealogyLink {
        return $this->recordEdge(
            parentLotId: $substituteLot->id,
            childLotId: null,
            customerOrderId: null,
            qtyConsumed: $qtyConsumed,
            unit: $unit ?? $substituteLot->unit,
            consumedAt: $consumedAt ?? now(),
            sourceEventType: 'substitution',
            sourceEventId: $transactionId,
        );
    }

    /**
     * Plan-022 T7.1 — append a reversal edge when a batch is cancelled from
     * `in_progress`. Original consumption rows stay (append-only); the
     * reversal row carries `source_event_type='reversal'` so TraceService
     * can net it out of recall blast radius.
     */
    public function recordReversal(
        MaterialLot $parentLot,
        ?MaterialLot $childLot,
        float $qtyConsumed,
        ?string $unit,
        string $sourceEventId,
        ?\DateTimeInterface $consumedAt = null,
    ): GenealogyLink {
        return $this->recordEdge(
            parentLotId: $parentLot->id,
            childLotId: $childLot?->id,
            customerOrderId: null,
            qtyConsumed: $qtyConsumed,
            unit: $unit ?? $parentLot->unit,
            consumedAt: $consumedAt ?? now(),
            sourceEventType: 'reversal',
            sourceEventId: $sourceEventId,
        );
    }

    /**
     * Compensating manual adjustment — used when ops detect a recorded link
     * was wrong. The original row stays (append-only); a new row records the
     * correction with the OPPOSITE qty sign convention is NOT used (qty stays
     * positive). Reports filter by source_event_type to compute net flow.
     */
    public function recordManualAdjustment(
        MaterialLot $parentLot,
        ?MaterialLot $childLot,
        float $qtyConsumed,
        ?string $unit,
        string $adjustmentId,
        ?\DateTimeInterface $consumedAt = null,
    ): GenealogyLink {
        return $this->recordEdge(
            parentLotId: $parentLot->id,
            childLotId: $childLot?->id,
            customerOrderId: null,
            qtyConsumed: $qtyConsumed,
            unit: $unit ?? $parentLot->unit,
            consumedAt: $consumedAt ?? now(),
            sourceEventType: 'manual_adjustment',
            sourceEventId: $adjustmentId,
        );
    }

    /**
     * Idempotent edge writer (plan-040 H12/TG.6). Keys on the natural identity
     * (parent_lot_id, child_lot_id, source_event_type, source_event_id) — the
     * same tuple as the `uq_genealogy_edge` DB unique (TA.1) — so re-recording
     * the same edge (e.g. a retried sync or a replayed batch completion) is a
     * no-op instead of a duplicate row or a unique-violation 500.
     */
    private function recordEdge(
        string $parentLotId,
        ?string $childLotId,
        ?string $customerOrderId,
        float $qtyConsumed,
        ?string $unit,
        \DateTimeInterface $consumedAt,
        string $sourceEventType,
        string $sourceEventId,
    ): GenealogyLink {
        return DB::transaction(fn () => GenealogyLink::firstOrCreate(
            [
                'parent_lot_id' => $parentLotId,
                'child_lot_id' => $childLotId,
                'source_event_type' => $sourceEventType,
                'source_event_id' => $sourceEventId,
            ],
            [
                'customer_order_id' => $customerOrderId,
                'qty_consumed' => $qtyConsumed,
                'unit' => $unit,
                'consumed_at' => $consumedAt,
            ],
        ));
    }
}
